-- TecnoTrade XAMPP · PHP 8.1+ / MariaDB 10.4+ / MySQL 8.
-- Importar dentro de una base vacía utf8mb4. No elimina registros existentes.
CREATE TABLE IF NOT EXISTS users (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 salt VARCHAR(100) NOT NULL DEFAULT '',
 role VARCHAR(20) NOT NULL CHECK (role IN ('cliente','admin','logistica','soporte','tecnico')),
 active TINYINT NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
 phone VARCHAR(30) NOT NULL DEFAULT '',
 address TEXT NOT NULL DEFAULT (''),
 created_at VARCHAR(24) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 csrf_token VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at BIGINT NOT NULL,
 created_at VARCHAR(24) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX idx_sessions_user (user_id), INDEX idx_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at BIGINT NOT NULL,
 used_at VARCHAR(24) NULL,
 created_at VARCHAR(24) NOT NULL,
 seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
 FOREIGN KEY (user_id) REFERENCES users(id), INDEX idx_resets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 name VARCHAR(70) NOT NULL UNIQUE,
 icon VARCHAR(30) NOT NULL DEFAULT '',
 position INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploads (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 purpose VARCHAR(20) NOT NULL CHECK (purpose IN ('product','tradein')),
 mime VARCHAR(80) NOT NULL,
 filename VARCHAR(150) NOT NULL UNIQUE,
 size INT NOT NULL CHECK (size > 0),
 created_at VARCHAR(24) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id), INDEX idx_uploads_user (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 name VARCHAR(180) NOT NULL,
 category_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `condition` VARCHAR(20) NOT NULL CHECK (`condition` IN ('Nuevo','Seminuevo')),
 description TEXT NOT NULL DEFAULT (''),
 price_cents INT NOT NULL DEFAULT 0 CHECK (price_cents >= 0),
 old_price_cents INT NULL CHECK (old_price_cents IS NULL OR old_price_cents >= 0),
 stock INT NOT NULL DEFAULT 0 CHECK (stock >= 0),
 warranty_months INT NOT NULL DEFAULT 0 CHECK (warranty_months BETWEEN 0 AND 120),
 storage VARCHAR(100) NOT NULL DEFAULT '',
 screen VARCHAR(100) NOT NULL DEFAULT '',
 camera VARCHAR(100) NOT NULL DEFAULT '',
 image VARCHAR(200) NOT NULL DEFAULT '',
 published TINYINT NOT NULL DEFAULT 0 CHECK (published IN (0,1)),
 approved TINYINT NOT NULL DEFAULT 0 CHECK (approved IN (0,1)),
 archived TINYINT NOT NULL DEFAULT 0 CHECK (archived IN (0,1)),
 created_at VARCHAR(24) NOT NULL,
 FOREIGN KEY (category_id) REFERENCES categories(id),
 INDEX idx_products_public (published,archived,category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotions (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 title VARCHAR(180) NOT NULL,
 scope VARCHAR(20) NOT NULL CHECK (scope IN ('all','category','products')),
 category_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 product_ids LONGTEXT NOT NULL DEFAULT ('[]'),
 discount INT NOT NULL CHECK (discount BETWEEN 1 AND 90),
 `start` VARCHAR(10) NOT NULL,
 `end` VARCHAR(10) NOT NULL,
 active TINYINT NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
 FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS carts (
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 items LONGTEXT NOT NULL DEFAULT ('[]'),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS favorites (
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 product_ids LONGTEXT NOT NULL DEFAULT ('[]'),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_accounts (
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 balance_cents BIGINT NOT NULL DEFAULT 0 CHECK (balance_cents >= 0),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 idempotency_key VARCHAR(120) COLLATE utf8mb4_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 status VARCHAR(50) NOT NULL,
 subtotal_cents INT NOT NULL CHECK (subtotal_cents >= 0),
 shipping_cents INT NOT NULL DEFAULT 0 CHECK (shipping_cents >= 0),
 total_cents INT NOT NULL CHECK (total_cents >= 0),
 wallet_applied_cents INT NOT NULL DEFAULT 0 CHECK (wallet_applied_cents >= 0),
 amount_due_cents INT NOT NULL CHECK (amount_due_cents >= 0),
 customer VARCHAR(100) NOT NULL,
 address TEXT NOT NULL DEFAULT (''),
 location VARCHAR(240) NOT NULL DEFAULT '',
 carrier VARCHAR(80) NOT NULL DEFAULT '',
 tracking VARCHAR(100) NOT NULL DEFAULT '',
 note TEXT NOT NULL DEFAULT (''),
 stock_restored TINYINT NOT NULL DEFAULT 0 CHECK (stock_restored IN (0,1)),
 tradein_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 created_at VARCHAR(24) NOT NULL,
 updated_at VARCHAR(24) NOT NULL,
 UNIQUE KEY unique_order_request (user_id,idempotency_key),
 FOREIGN KEY (user_id) REFERENCES users(id), INDEX idx_orders_user (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 name VARCHAR(180) NOT NULL,
 `condition` VARCHAR(20) NOT NULL,
 quantity INT NOT NULL CHECK (quantity > 0),
 unit_price_cents INT NOT NULL CHECK (unit_price_cents > 0),
 FOREIGN KEY (order_id) REFERENCES orders(id), FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_events (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 status VARCHAR(50) NOT NULL,
 location VARCHAR(240) NOT NULL DEFAULT '',
 created_at VARCHAR(24) NOT NULL,
 seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
 FOREIGN KEY (order_id) REFERENCES orders(id), FOREIGN KEY (actor_id) REFERENCES users(id),
 INDEX idx_order_events_order (order_id,seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount_cents INT NOT NULL CHECK (amount_cents > 0),
 reference VARCHAR(160) NOT NULL UNIQUE,
 note TEXT NOT NULL DEFAULT (''),
 created_at VARCHAR(24) NOT NULL,
 FOREIGN KEY (order_id) REFERENCES orders(id), FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_entries (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 delta_cents BIGINT NOT NULL,
 balance_after_cents BIGINT NOT NULL CHECK (balance_after_cents >= 0),
 reason VARCHAR(250) NOT NULL,
 source_key VARCHAR(191) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 order_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 tradein_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at VARCHAR(24) NOT NULL,
 seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
 FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (order_id) REFERENCES orders(id),
 INDEX idx_wallet_entries_user (user_id,seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS market_references (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 model_key VARCHAR(100) NOT NULL,
 model VARCHAR(180) NOT NULL,
 storage VARCHAR(100) NOT NULL DEFAULT '',
 category VARCHAR(100) NOT NULL DEFAULT 'telefonos',
 amount_cents INT NOT NULL CHECK (amount_cents > 0),
 source_url TEXT NOT NULL,
 source_title VARCHAR(250) NOT NULL DEFAULT '',
 `condition` VARCHAR(100) NOT NULL DEFAULT '',
 seller VARCHAR(180) NOT NULL,
 availability VARCHAR(80) NOT NULL,
 availability_confirmed TINYINT NOT NULL DEFAULT 0 CHECK (availability_confirmed IN (0,1)),
 checked_at VARCHAR(24) NOT NULL,
 expires_at VARCHAR(24) NOT NULL,
 notes TEXT NOT NULL DEFAULT (''),
 active TINYINT NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
 INDEX idx_market_model (model_key,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tradeins (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 target_product_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 model_key VARCHAR(100) NOT NULL,
 `condition` VARCHAR(20) NOT NULL,
 notes TEXT NOT NULL DEFAULT (''),
 photo_ids LONGTEXT NOT NULL DEFAULT ('[]'),
 estimated_cents INT NULL CHECK (estimated_cents IS NULL OR estimated_cents > 0),
 offer_cents INT NULL CHECK (offer_cents IS NULL OR offer_cents > 0),
 status VARCHAR(50) NOT NULL,
 serial_normalized VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 active_serial VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS
   (CASE WHEN status IN ('Oferta disponible','Aceptado') THEN serial_normalized ELSE NULL END) STORED,
 inspection LONGTEXT NULL,
 technician_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 order_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 created_at VARCHAR(24) NOT NULL,
 updated_at VARCHAR(24) NOT NULL,
 UNIQUE KEY unique_trade_serial_active (active_serial),
 FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (target_product_id) REFERENCES products(id),
 FOREIGN KEY (technician_id) REFERENCES users(id), FOREIGN KEY (order_id) REFERENCES orders(id),
 INDEX idx_tradeins_user (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 product_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 technician_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 status VARCHAR(50) NOT NULL DEFAULT 'Pendiente',
 serial VARCHAR(100) NULL UNIQUE,
 checks LONGTEXT NOT NULL DEFAULT ('[]'),
 battery_percent INT NULL CHECK (battery_percent IS NULL OR battery_percent BETWEEN 0 AND 100),
 grade VARCHAR(80) NULL,
 notes TEXT NOT NULL DEFAULT (''),
 created_at VARCHAR(24) NOT NULL,
 reviewed_at VARCHAR(24) NULL,
 seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
 FOREIGN KEY (product_id) REFERENCES products(id), FOREIGN KEY (technician_id) REFERENCES users(id),
 INDEX idx_reviews_product (product_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 order_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reason VARCHAR(100) NOT NULL,
 status VARCHAR(50) NOT NULL DEFAULT 'Abierto',
 created_at VARCHAR(24) NOT NULL,
 updated_at VARCHAR(24) NOT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (order_id) REFERENCES orders(id),
 INDEX idx_tickets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 ticket_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 author_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 text TEXT NOT NULL,
 created_at VARCHAR(24) NOT NULL,
 seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE,
 FOREIGN KEY (ticket_id) REFERENCES tickets(id), FOREIGN KEY (author_id) REFERENCES users(id),
 INDEX idx_messages_ticket (ticket_id,seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
 id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 actor_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 action VARCHAR(100) NOT NULL,
 entity_id VARCHAR(160) NULL,
 detail LONGTEXT NOT NULL DEFAULT ('{}'),
 created_at VARCHAR(24) NOT NULL,
 FOREIGN KEY (actor_id) REFERENCES users(id), INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
 bucket_key VARCHAR(191) COLLATE utf8mb4_bin PRIMARY KEY,
 hits_json LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
