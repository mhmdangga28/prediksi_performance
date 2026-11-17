
import pandas as pd
import numpy as np
import joblib
import os
import sys
from pathlib import Path

os.environ["LOKY_MAX_CPU_COUNT"] = "4"


# ==========================================
# KONFIGURASI
# ==========================================

BASE_DIR = Path(__file__).resolve().parent

DATASET = BASE_DIR / "sf_ml_training_v2.csv"
MODEL_DIR = BASE_DIR / "models"

MODEL_RF = MODEL_DIR / "random_forest_v2.joblib"
MODEL_POISSON = MODEL_DIR / "gradient_boosting_poisson_v3.joblib"
FEATURE_FILE = MODEL_DIR / "feature_columns.joblib"

# Contoh tanggal testing historis
TANGGAL_PREDIKSI = (
    sys.argv[1] if len(sys.argv) > 1
    else "2026-09-24"
)

# ==========================================
# 1. LOAD MODEL
# ==========================================

model_rf = joblib.load(MODEL_RF)
model_poisson = joblib.load(MODEL_POISSON)
features = joblib.load(FEATURE_FILE)

print("Model berhasil dimuat.")

# ==========================================
# 2. BACA DATASET
# ==========================================

df = pd.read_csv(
    DATASET,
    sep=None,
    engine="python",
    dtype={"sf_code": str}
)

df["tanggal"] = pd.to_datetime(df["tanggal"])

tanggal = pd.to_datetime(TANGGAL_PREDIKSI)

data = df.loc[
    df["tanggal"] == tanggal
].copy()

if data.empty:
    raise ValueError(
        f"Tidak ada fitur untuk {TANGGAL_PREDIKSI}. "
        "Dataset ini hanya berisi periode historis."
    )

# ==========================================
# 3. VALIDASI FITUR
# ==========================================

missing = [
    col for col in features
    if col not in data.columns
]

if missing:
    raise ValueError(
        f"Kolom fitur tidak ditemukan: {missing}"
    )

for col in features:
    data[col] = pd.to_numeric(
        data[col],
        errors="coerce"
    )

if data[features].isna().any().any():
    raise ValueError(
        "Ada fitur kosong atau tidak valid."
    )

if data["sf_code"].duplicated().any():
    raise ValueError(
        "Ada kode SF duplikat pada tanggal prediksi."
    )

# ==========================================
# 4. PREDIKSI
# ==========================================

X = data[features]

data["prediksi_rf"] = np.maximum(
    model_rf.predict(X),
    0
)

data["prediksi_poisson"] = np.maximum(
    model_poisson.predict(X),
    0
)

data["prediksi_baseline"] = (
    data["re_7d"] / 7
)

# ==========================================
# 5. HASIL PER SALES FORCE
# ==========================================

hasil = data[
    [
        "tanggal",
        "sf_code",
        "prediksi_baseline",
        "prediksi_rf",
        "prediksi_poisson"
    ]
].copy()

hasil = hasil.sort_values(
    "prediksi_rf",
    ascending=False
)

print("\n=== PREDIKSI RE PER SF ===")
print(hasil.head(20).to_string(index=False))

# ==========================================
# 6. TOTAL PREDIKSI BRANCH
# ==========================================

print("\n=== TOTAL PREDIKSI BRANCH ===")
print("Tanggal:", TANGGAL_PREDIKSI)
print("Jumlah SF:", len(hasil))

print(
    "Baseline:",
    round(hasil["prediksi_baseline"].sum(), 2)
)

print(
    "Random Forest:",
    round(hasil["prediksi_rf"].sum(), 2)
)

print(
    "Poisson:",
    round(hasil["prediksi_poisson"].sum(), 2)
)

# Realisasi hanya untuk pengujian historis
if "total_re" in data.columns:
    realisasi = pd.to_numeric(
        data["total_re"],
        errors="coerce"
    ).sum()

    print("Realisasi RE:", int(realisasi))

# ==========================================
# 7. SIMPAN PREDIKSI
# ==========================================

output_dir = BASE_DIR / "predictions"
output_dir.mkdir(exist_ok=True)

output_file = (
    output_dir /
    f"prediksi_re_{TANGGAL_PREDIKSI}.csv"
)

hasil.to_csv(
    output_file,
    index=False
)

print("\nPrediksi berhasil disimpan:")
print(output_file)
