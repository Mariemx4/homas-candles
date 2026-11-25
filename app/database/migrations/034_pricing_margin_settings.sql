-- Pricing margins: global settings, margin groups and a per-product margin override.
-- Suggested prices are advice only. Products keep their actual selling_price, which this
-- migration never changes. Waste, density and rounding are owner settings (see
-- docs/pricing.md); labour, overhead, packaging and wicks are not part of version 1.

CREATE TABLE pricing_settings (
    setting_key VARCHAR(60) NOT NULL,
    setting_value NUMERIC(12, 4) NOT NULL,
    CONSTRAINT pk_pricing_settings PRIMARY KEY (setting_key),
    CONSTRAINT chk_pricing_settings_value_nonnegative CHECK (setting_value >= 0)
);

-- Margins in percent. Waste in percent. Density in grams per millilitre. Rounding in TND.
INSERT INTO pricing_settings (setting_key, setting_value) VALUES
    ('margin_small_candle', 45),
    ('margin_standard_floral', 50),
    ('margin_large_decorative', 50),
    ('margin_bouquet_gift', 55),
    ('margin_custom', 55),
    ('waste_percent', 10),
    ('density_g_per_ml', 1),
    ('rounding_step_tnd', 0.5),
    ('minimum_margin_warning_percent', 40);

ALTER TABLE products
    ADD COLUMN margin_group VARCHAR(30) NULL,
    ADD COLUMN target_margin_percent NUMERIC(5, 2) NULL,
    ADD CONSTRAINT chk_products_margin_group CHECK (
        margin_group IS NULL OR margin_group IN ('SMALL_CANDLE', 'STANDARD_FLORAL', 'LARGE_DECORATIVE', 'BOUQUET_GIFT', 'CUSTOM')
    ),
    ADD CONSTRAINT chk_products_target_margin CHECK (
        target_margin_percent IS NULL OR (target_margin_percent > 0 AND target_margin_percent < 100)
    );
