from sklearn.metrics import (
    mean_absolute_error,
    mean_squared_error
)
from sklearn.ensemble import (
    RandomForestRegressor,
    HistGradientBoostingRegressor
)
import numpy as np
import pandas as pd
import joblib
import os

os.environ["LOKY_MAX_CPU_COUNT"] = "4"


# ==========================================
# 1. MEMBACA DATASET
# ==========================================

df = pd.read_csv("sf_ml_training_v2.csv", sep=None, engine="python")

df["tanggal"] = pd.to_datetime(df["tanggal"])

features = [
    "hari_ke",
    "bulan",
    "re_1d",
    "re_3d",
    "re_7d",
    "re_14d",
    "re_30d",
    "rata_re_7d",
    "hari_menghasilkan_re_7d",
    "ps_7d",
    "ps_30d"
]

for col in features + ["total_re"]:
    df[col] = pd.to_numeric(df[col], errors="coerce")

df = df.dropna(subset=features + ["total_re"])
df = df.sort_values(["tanggal", "sf_code"])

# ==========================================
# 2. TRAIN / TEST BERDASARKAN WAKTU
# ==========================================

train = df[
    df["tanggal"] <= "2026-09-15"
].copy()

test = df[
    df["tanggal"] >= "2026-09-16"
].copy()

if train.empty or test.empty:
    raise ValueError("Data training atau testing kosong.")

print("Training:", len(train), "baris")
print("Testing :", len(test), "baris")

# ==========================================
# 3. BASELINE HISTORIS
# ==========================================

test["baseline_re"] = test["re_7d"] / 7

# ==========================================
# 4. RANDOM FOREST
# ==========================================

model = RandomForestRegressor(
    n_estimators=200,
    min_samples_leaf=5,
    random_state=42,
    n_jobs=-1
)

model.fit(train[features], train["total_re"])

test["prediksi_re"] = np.maximum(
    model.predict(test[features]),
    0
)

# ==========================================
# 5. EVALUASI PER SF
# ==========================================

actual = test["total_re"]

mae_baseline = mean_absolute_error(
    actual, test["baseline_re"]
)

mae_model = mean_absolute_error(
    actual, test["prediksi_re"]
)

rmse_model = np.sqrt(
    mean_squared_error(actual, test["prediksi_re"])
)

print("\n=== EVALUASI PER SF PER HARI ===")
print("MAE Baseline     :", round(mae_baseline, 4))
print("MAE RandomForest :", round(mae_model, 4))
print("RMSE RandomForest:", round(rmse_model, 4))

# ==========================================
# 6. EVALUASI TOTAL BRANCH PER HARI
# ==========================================

branch = test.groupby("tanggal", as_index=False).agg(
    realisasi_re=("total_re", "sum"),
    baseline_re=("baseline_re", "sum"),
    prediksi_re=("prediksi_re", "sum")
)

print("\n=== EVALUASI TOTAL BRANCH ===")
print(
    "MAE Baseline:",
    round(
        mean_absolute_error(
            branch["realisasi_re"],
            branch["baseline_re"]
        ), 4
    )
)

print(
    "MAE RandomForest:",
    round(
        mean_absolute_error(
            branch["realisasi_re"],
            branch["prediksi_re"]
        ), 4
    )
)

print("\n=== PREDIKSI HARIAN BRANCH ===")
print(branch.to_string(index=False))

# ==========================================
# 7. SIMPAN HASIL
# ==========================================

test[
    ["tanggal", "sf_code", "total_re",
     "baseline_re", "prediksi_re"]
].to_csv(
    "hasil_prediksi_sf.csv",
    index=False
)

branch.to_csv(
    "hasil_prediksi_branch.csv",
    index=False
)

print("\nFile hasil prediksi berhasil dibuat.")

# ==========================================
# EVALUASI ERROR PER SALES FORCE
# ==========================================

test["error_ai"] = abs(
    test["total_re"] - test["prediksi_re"]
)

test["error_baseline"] = abs(
    test["total_re"] - test["baseline_re"]
)

evaluasi_sf = (
    test.groupby("sf_code")
    .agg(
        total_re_aktual=("total_re", "sum"),
        rata_re_aktual=("total_re", "mean"),
        mae_ai=("error_ai", "mean"),
        mae_baseline=("error_baseline", "mean")
    )
    .reset_index()
    .sort_values("mae_ai", ascending=False)
)

print("\n=== 15 SF DENGAN ERROR TERTINGGI ===")
print(evaluasi_sf.head(15).to_string(index=False))

evaluasi_sf.to_csv(
    "evaluasi_per_sf.csv",
    index=False
)


# ==========================================
# MODEL V3: GRADIENT BOOSTING POISSON
# ==========================================

model_poisson = HistGradientBoostingRegressor(
    loss="poisson",
    max_iter=200,
    learning_rate=0.05,
    max_leaf_nodes=15,
    min_samples_leaf=20,
    l2_regularization=1.0,
    random_state=42
)

model_poisson.fit(
    train[features],
    train["total_re"]
)

test["prediksi_poisson"] = np.maximum(
    model_poisson.predict(test[features]),
    0
)

mae_poisson = mean_absolute_error(
    test["total_re"],
    test["prediksi_poisson"]
)

print("\n=== EVALUASI MODEL POISSON ===")
print("MAE Baseline     :", round(mae_baseline, 4))
print("MAE RandomForest :", round(mae_model, 4))
print("MAE Poisson      :", round(mae_poisson, 4))

branch_poisson = test.groupby(
    "tanggal",
    as_index=False
).agg(
    realisasi_re=("total_re", "sum"),
    prediksi_poisson=("prediksi_poisson", "sum")
)

mae_branch_poisson = mean_absolute_error(
    branch_poisson["realisasi_re"],
    branch_poisson["prediksi_poisson"]
)

print(
    "MAE Branch Poisson:",
    round(mae_branch_poisson, 4)
)

# ==========================================
# SIMPAN HASIL SEMUA MODEL
# ==========================================

test[
    [
        "tanggal",
        "sf_code",
        "total_re",
        "baseline_re",
        "prediksi_re",
        "prediksi_poisson"
    ]
].to_csv(
    "hasil_prediksi_semua_model_sf.csv",
    index=False
)

branch_comparison = test.groupby(
    "tanggal",
    as_index=False
).agg(
    realisasi_re=("total_re", "sum"),
    baseline_re=("baseline_re", "sum"),
    random_forest_re=("prediksi_re", "sum"),
    poisson_re=("prediksi_poisson", "sum")
)

branch_comparison.to_csv(
    "hasil_prediksi_semua_model_branch.csv",
    index=False
)

print("\nHasil perbandingan semua model berhasil disimpan.")


# ==========================================
# 8. SIMPAN MODEL MACHINE LEARNING
# ==========================================

os.makedirs("models", exist_ok=True)

# Simpan Random Forest V2
joblib.dump(
    model,
    "models/random_forest_v2.joblib"
)

# Simpan Gradient Boosting Poisson V3
joblib.dump(
    model_poisson,
    "models/gradient_boosting_poisson_v3.joblib"
)

# Simpan daftar fitur model
joblib.dump(
    features,
    "models/feature_columns.joblib"
)

print("\nModel Machine Learning berhasil disimpan.")
