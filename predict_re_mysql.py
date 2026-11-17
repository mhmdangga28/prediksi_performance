
from dotenv import load_dotenv
import pymysql
import pandas as pd
import numpy as np
import joblib
from pathlib import Path
import sys
import os
os.environ["LOKY_MAX_CPU_COUNT"] = "4"


# ==========================================
# 1. KONFIGURASI
# ==========================================

BASE_DIR = Path(__file__).resolve().parent
load_dotenv(BASE_DIR / ".env")

MODEL_DIR = BASE_DIR / "models"

# Khusus pengujian historis
TANGGAL_PREDIKSI = (
    sys.argv[1] if len(sys.argv) > 1
    else "2026-09-24"
)

tanggal = pd.Timestamp(TANGGAL_PREDIKSI).date()

# Model hanya diuji untuk periode ini.
# Jangan gunakan sebagai prediksi live.
if not (
    pd.Timestamp("2026-09-16").date()
    <= tanggal <=
    pd.Timestamp("2026-09-24").date()
):
    raise ValueError(
        "Script pengujian ini hanya menerima "
        "tanggal 2026-09-16 sampai 2026-09-24."
    )

# ==========================================
# 2. LOAD MODEL
# ==========================================

model_rf = joblib.load(
    MODEL_DIR / "random_forest_v2.joblib"
)

model_poisson = joblib.load(
    MODEL_DIR / "gradient_boosting_poisson_v3.joblib"
)

features = joblib.load(
    MODEL_DIR / "feature_columns.joblib"
)

print("Model berhasil dimuat.")

# ==========================================
# 3. KONEKSI MYSQL
# ==========================================

conn = pymysql.connect(
    host=os.getenv("DB_HOST", "localhost"),
    port=int(os.getenv("DB_PORT", "3306")),
    user=os.getenv("DB_USER", "root"),
    password=os.getenv("DB_PASSWORD", ""),
    database=os.getenv("DB_NAME", "sf_attendance"),
    charset="utf8mb4",
    cursorclass=pymysql.cursors.DictCursor,
    autocommit=False
)

try:
    print("Koneksi MySQL berhasil.")

    # ======================================
    # 4. AMBIL FITUR DARI MYSQL
    # ======================================

    query = """
        SELECT
            tanggal,
            sf_code,
            hari_ke,
            bulan,
            re_1d,
            re_3d,
            re_7d,
            re_14d,
            re_30d,
            rata_re_7d,
            hari_menghasilkan_re_7d,
            ps_7d,
            ps_30d,
            total_re
        FROM vw_sf_ml_features_v2
        WHERE tanggal = %s
        ORDER BY sf_code
    """

    with conn.cursor() as cursor:
        cursor.execute(query, (tanggal,))
        rows = cursor.fetchall()

    if not rows:
        raise ValueError(
            "Tidak ada data fitur pada tanggal "
            + str(tanggal)
        )

    df = pd.DataFrame(rows)

    print("Data SF ditemukan:", len(df))

    if df["sf_code"].duplicated().any():
        raise ValueError(
            "Ditemukan SF duplikat."
        )

    # ======================================
    # 5. VALIDASI FITUR
    # ======================================

    for col in features:
        if col not in df.columns:
            raise ValueError(
                f"Kolom tidak ditemukan: {col}"
            )

        df[col] = pd.to_numeric(
            df[col],
            errors="coerce"
        )

    if df[features].isna().any().any():
        raise ValueError(
            "Fitur mengandung nilai kosong."
        )

    # ======================================
    # 6. PREDIKSI RE
    # ======================================

    X = df[features]

    df["baseline"] = df["re_7d"] / 7

    df["random_forest"] = np.maximum(
        model_rf.predict(X), 0
    )

    df["poisson"] = np.maximum(
        model_poisson.predict(X), 0
    )

    # ======================================
    # 7. SIMPAN KE MYSQL
    # ======================================

    insert_sql = """
        INSERT INTO sf_re_predictions
        (
            prediction_date,
            sf_code,
            model_name,
            predicted_re,
            actual_re
        )
        VALUES (%s, %s, %s, %s, %s)

        ON DUPLICATE KEY UPDATE
            predicted_re = VALUES(predicted_re),
            actual_re = VALUES(actual_re),
            updated_at = CURRENT_TIMESTAMP
    """

    model_columns = {
        "BACKTEST_BASELINE_7D": "baseline",
        "BACKTEST_RF_V2": "random_forest",
        "BACKTEST_POISSON_V3": "poisson"
    }

    records = []

    for _, row in df.iterrows():
        actual = int(row["total_re"])

        for model_name, column in model_columns.items():
            records.append((
                tanggal,
                str(row["sf_code"]),
                model_name,
                round(float(row[column]), 4),
                actual
            ))

    with conn.cursor() as cursor:
        cursor.executemany(
            insert_sql,
            records
        )

    conn.commit()

    print(
        "\nData prediksi berhasil disimpan:",
        len(records),
        "baris"
    )

    # ======================================
    # 8. RINGKASAN PREDIKSI
    # ======================================

    print("\n=== RINGKASAN BRANCH ===")
    print("Tanggal:", tanggal)
    print("Jumlah SF:", len(df))

    print(
        "Baseline:",
        round(df["baseline"].sum(), 2)
    )

    print(
        "Random Forest:",
        round(df["random_forest"].sum(), 2)
    )

    print(
        "Poisson:",
        round(df["poisson"].sum(), 2)
    )

    print(
        "Realisasi:",
        int(df["total_re"].sum())
    )

except Exception:
    conn.rollback()
    raise

finally:
    conn.close()
