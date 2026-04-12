from pydantic_settings import BaseSettings
from pathlib import Path


class Settings(BaseSettings):
    BASE_DIR: Path = Path(__file__).parent
    DATA_DIR: Path = Path(__file__).parent / "data" / "candles"
    RESULTS_DIR: Path = Path(__file__).parent / "results"
    STRATEGIES_DIR: Path = Path(__file__).parent / "strategies"
    CONFIGS_DIR: Path = Path(__file__).parent / "strategies" / "configs"
    DB_PATH: Path = Path(__file__).parent / "db.sqlite"

    DEFAULT_EXCHANGE: str = "binance"
    RATE_LIMIT_MS: int = 100

    STARTING_CAPITAL: float = 10_000.0
    DEFAULT_FEE_RATE: float = 0.001
    DEFAULT_SLIPPAGE_PCT: float = 0.0005
    MAX_LEVERAGE: float = 5.0

    DEFAULT_MAX_POSITION_PCT: float = 0.10
    DEFAULT_STOP_LOSS_PCT: float = 0.03
    DEFAULT_TAKE_PROFIT_PCT: float = 0.06
    MAX_DRAWDOWN_LIMIT: float = 0.25

    MIN_TRADE_COUNT: int = 50
    OVERFIT_THRESHOLD: float = 0.40

    class Config:
        env_prefix = "BT_"


settings = Settings()
