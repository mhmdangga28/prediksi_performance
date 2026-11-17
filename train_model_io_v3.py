"""Training/evaluasi RE: V2 (tanpa IO) vs V3 (dengan IO).

Sumber: VIEW sf_attendance.vw_sf_ml_training_v3.
Instalasi: pip install pandas numpy pymysql python-dotenv scikit-learn joblib
Jalankan: python train_model_io_v3.py
Output: models_io_v3/ dan hasil_evaluasi_io_v3/.
Tidak menimpa model lama dan tidak mengubah database.
"""
from __future__ import annotations

import os
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import pymysql
from dotenv import load_dotenv
from sklearn.ensemble import RandomForestRegressor, HistGradientBoostingRegressor
from sklearn.metrics import mean_absolute_error, mean_squared_error

BASE_DIR = Path(__file__).resolve().parent
load_dotenv(BASE_DIR / '.env')
MODEL_DIR = BASE_DIR / 'models_io_v3'
OUTPUT_DIR = BASE_DIR / 'hasil_evaluasi_io_v3'
MODEL_DIR.mkdir(exist_ok=True)
OUTPUT_DIR.mkdir(exist_ok=True)

V2_FEATURES = [
    'hari_ke', 'bulan', 're_1d', 're_3d', 're_7d', 're_14d', 're_30d',
    'rata_re_7d', 'hari_menghasilkan_re_7d', 'ps_7d', 'ps_30d'
]
IO_FEATURES = ['io_1d', 'io_3d', 'io_7d', 'io_14d', 'io_30d']
V3_FEATURES = V2_FEATURES + IO_FEATURES


def load_dataset() -> pd.DataFrame:
    conn = pymysql.connect(
        host=os.getenv('DB_HOST', '127.0.0.1'),
        port=int(os.getenv('DB_PORT', '3306')),
        user=os.getenv('DB_USER', 'root'),
        password=os.getenv('DB_PASSWORD') or os.getenv('DB_PASS', ''),
        database=os.getenv('DB_NAME', 'sf_attendance'),
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor,
        connect_timeout=15,
    )
    cols = ['tanggal', 'sf_code', 'total_re'] + V3_FEATURES
    try:
        with conn.cursor() as cur:
            cur.execute(
                'SELECT ' + ', '.join('`' + c + '`' for c in cols) +
                ' FROM `vw_sf_ml_training_v3` ORDER BY `tanggal`, `sf_code`'
            )
            records = cur.fetchall()
    finally:
        conn.close()
    if not records:
        raise ValueError('VIEW vw_sf_ml_training_v3 tidak menghasilkan data.')
    df = pd.DataFrame(records)
    df['tanggal'] = pd.to_datetime(df['tanggal'], errors='raise')
    df['sf_code'] = df['sf_code'].astype(str).str.strip()
    if df[['tanggal', 'sf_code']].duplicated().any():
        raise ValueError('Ada duplikasi pasangan (tanggal, sf_code). Periksa VIEW.')
    for c in ['total_re'] + V3_FEATURES:
        df[c] = pd.to_numeric(df[c], errors='coerce')
    invalid = df[['total_re'] + V3_FEATURES].isna().any(axis=1)
    if invalid.any():
        raise ValueError(f'Ada {int(invalid.sum())} baris dengan fitur/target NULL atau nonnumerik.')
    if (df[['total_re'] + V3_FEATURES] < 0).any().any():
        raise ValueError('Ditemukan nilai negatif pada target atau fitur.')
    return df


def models():
    return {
        'Random Forest': RandomForestRegressor(
            n_estimators=200, min_samples_leaf=5, random_state=42, n_jobs=-1
        ),
        'Gradient Boosting Poisson': HistGradientBoostingRegressor(
            loss='poisson', max_iter=200, learning_rate=0.05,
            max_leaf_nodes=15, min_samples_leaf=20, l2_regularization=1.0,
            random_state=42
        ),
    }


def main():
    df = load_dataset()
    print('Dataset:', len(df), 'baris,', df.sf_code.nunique(), 'SF,',
          df.tanggal.min().date(), 's/d', df.tanggal.max().date())
    train = df.loc[df.tanggal <= '2026-09-15'].copy()
    test = df.loc[(df.tanggal >= '2026-09-16') & (df.tanggal <= '2026-09-24')].copy()
    if train.empty or test.empty:
        raise ValueError('Periode training/testing kosong.')
    print('Train:', len(train), '| Test:', len(test))

    results = []
    predictions = test[['tanggal', 'sf_code', 'total_re']].copy()
    baseline = (test['re_7d'] / 7).to_numpy(dtype=float)

    def evaluate(name: str, y_pred: np.ndarray):
        y_pred = np.maximum(np.asarray(y_pred, dtype=float), 0)
        predictions[name] = y_pred
        actual_branch = test.groupby('tanggal')['total_re'].sum().sort_index()
        predicted_branch = pd.Series(y_pred, index=test.index).groupby(test['tanggal']).sum().sort_index()
        results.append({
            'metode': name,
            'mae_per_sf': float(mean_absolute_error(test['total_re'], y_pred)),
            'rmse_per_sf': float(np.sqrt(mean_squared_error(test['total_re'], y_pred))),
            'mae_branch': float(mean_absolute_error(actual_branch, predicted_branch)),
        })

    evaluate('Baseline_RE_7D', baseline)
    for version, features in [('V2_tanpa_IO', V2_FEATURES), ('V3_dengan_IO', V3_FEATURES)]:
        for model_name, model in models().items():
            model.fit(train[features], train['total_re'])
            name = f'{model_name}_{version}'
            evaluate(name, model.predict(test[features]))
            outname = ('rf' if model_name == 'Random Forest' else 'poisson') + '_' + version + '.joblib'
            joblib.dump(model, MODEL_DIR / outname)
        joblib.dump(features, MODEL_DIR / f'features_{version}.joblib')

    summary = pd.DataFrame(results).sort_values('mae_branch')
    summary.to_csv(OUTPUT_DIR / 'perbandingan_mae.csv', index=False)
    predictions.to_csv(OUTPUT_DIR / 'prediksi_per_sf.csv', index=False)
    branch = predictions.drop(columns='sf_code').groupby('tanggal', as_index=False).sum()
    branch.to_csv(OUTPUT_DIR / 'prediksi_branch.csv', index=False)
    print('\n=== PERBANDINGAN METODE (DATA TEST IDENTIK) ===')
    print(summary.to_string(index=False, float_format=lambda x: f'{x:.4f}'))
    print('\nOutput:', OUTPUT_DIR)
    print('Model baru disimpan di:', MODEL_DIR)
    print('Catatan: periode testing hanya 9 hari. Evaluasi perlu diperluas.')


if __name__ == '__main__':
    main()
