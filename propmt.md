You are an expert ML engineer. Your task is to build a complete, end-to-end Supply Chain Demand Forecasting system inside the current folder. Follow every step exactly. Pause and ask for human input only where explicitly marked ⚠️ HUMAN REQUIRED.

---

## STEP 1: CONDA ENVIRONMENT SETUP

Create a conda environment named `supply_forecast` with Python 3.10:

```bash
conda create -n supply_forecast python=3.10 -y
conda activate supply_forecast
pip install torch torchvision torchaudio --index-url https://download.pytorch.org/whl/cpu
pip install pytorch-forecasting pytorch-lightning
pip install pandas numpy scikit-learn matplotlib seaborn plotly
pip install xgboost lightgbm statsmodels
pip install shap optuna
pip install fastapi uvicorn pydantic
pip install streamlit
pip install evidently
pip install jupyter ipykernel
pip install mlflow
pip install python-dotenv requests
```

Save a `requirements.txt` file with all these packages pinned.

---

## STEP 2: PROJECT STRUCTURE

Create the following folder structure:
supply_chain_forecast/
├── data/
│   ├── raw/
│   ├── processed/
│   └── generate_data.py
├── notebooks/
│   └── 01_eda.ipynb
├── src/
│   ├── init.py
│   ├── data_pipeline.py
│   ├── feature_engineering.py
│   ├── baseline_models.py
│   ├── lstm_model.py
│   ├── tft_model.py
│   ├── explainability.py
│   └── mlops_pipeline.py
├── api/
│   ├── main.py
│   └── schemas.py
├── dashboard/
│   └── app.py
├── monitoring/
│   └── drift_monitor.py
├── tests/
│   └── test_pipeline.py
├── Dockerfile
├── docker-compose.yml
├── .env.example
├── requirements.txt
└── README.md

---

## STEP 3: SYNTHETIC DATASET GENERATION

Create `data/generate_data.py` that generates a realistic FMCG retail dataset with:
- 500 SKUs across 10 product categories
- 50 distribution centers
- 3 years of daily sales data (2021–2024)
- Features: sales, price, promotions (binary + discount %), holidays, day_of_week, week_of_year, month, lag features (7, 14, 30 days), rolling means (7, 14, 30 days), competitor_price, stock_level, web_search_trend (random walk), macroeconomic_index
- Realistic seasonality: Christmas, Diwali (October spike), summer peaks
- Controlled noise and occasional demand spikes (simulating promotions)
- Save as `data/raw/fmcg_sales.csv` and `data/raw/product_metadata.csv`

Run the script to generate the data immediately after creating it.

---

## STEP 4: EDA AND DATA PIPELINE

Create `src/data_pipeline.py` with:
- Load and validate raw CSVs
- Handle missing values (forward fill for time series)
- Detect and cap outliers using IQR
- Train/validation/test split (70/15/15 by time — no data leakage)
- Normalize numerical features using MinMaxScaler (save scaler to disk with joblib)
- Return PyTorch TimeSeriesDataSet objects for TFT

Create `src/feature_engineering.py` with:
- All lag features (7, 14, 30 days)
- Rolling statistics (mean, std, min, max) for 7, 14, 30-day windows
- Date features (day_of_week, week_of_year, month, quarter, is_weekend)
- Holiday encoder (binary flags for major Indian + global holidays)
- Promotion interaction features (price × promotion_flag)

---

## STEP 5: BASELINE MODELS

Create `src/baseline_models.py` implementing:

1. **ARIMA/SARIMA** — statsmodels, per-SKU, evaluate MAE/RMSE/WMAPE
2. **XGBoost Regressor** — tabular features, hyperparameter tuning with Optuna (50 trials)
3. **LightGBM** — same features, faster training

Save all models to `models/` directory using joblib. Print evaluation metrics for all three.

---

## STEP 6: LSTM MODEL

Create `src/lstm_model.py` with:
- Bidirectional LSTM with 2 layers, hidden_size=128, dropout=0.2
- Sequence length: 30 days input → 7 days forecast
- Training with Adam optimizer, ReduceLROnPlateau scheduler
- Early stopping (patience=10)
- Save best model checkpoint
- Evaluate on test set: MAE, RMSE, WMAPE

---

## STEP 7: TEMPORAL FUSION TRANSFORMER (MAIN MODEL)

Create `src/tft_model.py` implementing the full TFT using pytorch-forecasting:

```python
from pytorch_forecasting import TemporalFusionTransformer, TimeSeriesDataSet
from pytorch_forecasting.metrics import QuantileLoss
```

Configure TFT with:
- max_encoder_length = 90 (3 months history)
- max_prediction_length = 30 (1 month forecast)
- static_categoricals: ["sku_id", "category", "distribution_center"]
- static_reals: ["avg_price"]
- time_varying_known_reals: ["price", "promotion_discount", "is_holiday", "day_of_week", "week_of_year", "competitor_price"]
- time_varying_unknown_reals: ["sales", "stock_level", "web_search_trend", "lag_7", "lag_14", "lag_30", "rolling_mean_7", "rolling_mean_30"]
- hidden_size=64, attention_head_size=4, dropout=0.1
- Train for 30 epochs with gradient clipping
- Save best model with ModelCheckpoint callback
- Evaluate: MAE, RMSE, WMAPE — target 15-20% improvement over ARIMA baseline
- Plot sample forecasts for 5 random SKUs

---

## STEP 8: SHAP EXPLAINABILITY

Create `src/explainability.py` with:
- SHAP TreeExplainer for XGBoost model
- Feature importance bar plots (top 20 features)
- SHAP summary plot (beeswarm)
- TFT built-in attention weights visualization (encoder attention, variable importance)
- Save all plots to `outputs/explainability/`

---

## STEP 9: MLOPS PIPELINE

Create `src/mlops_pipeline.py` with:
- MLflow experiment tracking: log all hyperparameters, metrics, and model artifacts
- Automated retraining trigger: if WMAPE on latest 7 days exceeds 15%, trigger retraining
- Model versioning: compare new model vs. production model before promoting
- Optuna hyperparameter optimization for TFT (20 trials)
- Save best hyperparameters to `config/best_params.json`

---

## STEP 10: FASTAPI BACKEND

Create `api/main.py` with these endpoints:
- `POST /forecast` — accepts SKU ID, distribution center, horizon (days), returns forecast with confidence intervals
- `GET /model/metrics` — returns current model performance metrics
- `GET /model/feature_importance` — returns top feature importances
- `POST /retrain` — triggers model retraining (async background task)
- `GET /health` — health check

Create `api/schemas.py` with Pydantic models for all request/response schemas.

Include CORS middleware, request logging, and error handling.

---

## STEP 11: STREAMLIT DASHBOARD

Create `dashboard/app.py` with:
- Sidebar: SKU selector, distribution center selector, forecast horizon slider (1–90 days)
- Page 1 — Forecast View: line chart with historical + predicted sales + confidence bands (Plotly), KPI cards (WMAPE, MAE, RMSE)
- Page 2 — SKU Analytics: top 10 stockout-risk SKUs, inventory heatmap by distribution center
- Page 3 — Explainability: feature importance bar chart, SHAP beeswarm plot
- Page 4 — Model Monitoring: drift score over time, retraining history
- Page 5 — Comparison: ARIMA vs LSTM vs TFT side-by-side metrics table
- Use a clean dark theme with Plotly charts

---

## STEP 12: DRIFT MONITORING

Create `monitoring/drift_monitor.py` using Evidently AI:
- DataDriftPreset report comparing training distribution vs. last 30 days of data
- TargetDriftPreset for sales target drift
- Save HTML reports to `outputs/monitoring/`
- Log drift scores to MLflow
- Print alert if drift score > 0.2

---

## STEP 13: DOCKER

Create `Dockerfile`:
```dockerfile
FROM python:3.10-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
EXPOSE 8000 8501
CMD ["bash", "-c", "uvicorn api.main:app --host 0.0.0.0 --port 8000 & streamlit run dashboard/app.py --server.port 8501"]
```

Create `docker-compose.yml` with services for API and dashboard.

---

## STEP 14: TESTS

Create `tests/test_pipeline.py` with:
- Test data generation produces correct shape
- Test feature engineering produces no NaN values
- Test FastAPI `/health` endpoint returns 200
- Test forecast endpoint returns valid JSON with correct keys
- Run with `pytest tests/ -v`

---

## STEP 15: README

Create a comprehensive `README.md` with:
- Project overview and business problem
- Architecture diagram (ASCII)
- Setup instructions (conda env, data generation, training, API, dashboard)
- Model performance comparison table (ARIMA vs LSTM vs TFT)
- API endpoint documentation
- AWS Free Tier deployment instructions

---

## ⚠️ HUMAN REQUIRED — Points Where You Must Intervene:

1. **After STEP 3** — Run `python data/generate_data.py` yourself and confirm `data/raw/fmcg_sales.csv` was created with no errors before proceeding.

2. **After STEP 7 (TFT Training)** — TFT training can take 15–45 minutes on CPU. Start the training run and monitor it. If loss is not decreasing after 5 epochs, stop and tell the agent to reduce `hidden_size` to 32 and reduce dataset to 50 SKUs for a faster debug run.

3. **After STEP 9 (MLflow)** — Run `mlflow ui` yourself in a terminal to verify experiments are being logged at `http://localhost:5000`. Confirm models appear before telling the agent to proceed.

4. **After STEP 10 (API)** — Run `uvicorn api.main:app --reload` and manually test the `/forecast` endpoint with a sample SKU ID using Postman or curl. Confirm the response JSON matches the expected schema.

5. **After STEP 11 (Dashboard)** — Run `streamlit run dashboard/app.py` yourself and visually verify all 5 pages load correctly with charts rendering.

6. **AWS Deployment (Optional, Final Step)** — You must manually create an AWS account, set up EC2 t2.micro, configure security groups (open ports 8000, 8501), and paste the EC2 instance IP for the agent to update the `.env` file with the correct `API_BASE_URL`.

---

When done, print a summary table of all model metrics and confirm which files were created.