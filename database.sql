CREATE DATABASE IF NOT EXISTS `final_project` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `final_project`;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
    grams_per_piece DECIMAL(10,2) NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
    reorder_level DECIMAL(12,4) NOT NULL DEFAULT 5,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    price DECIMAL(10,2) NOT NULL
);

-- One row per ingredient used by one serving of a menu item
CREATE TABLE IF NOT EXISTS menu_item_ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    quantity DECIMAL(12,4) NOT NULL,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id)
);

CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ingredient_id INT NOT NULL,
    quantity DECIMAL(12,4) NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT NULL,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id),
    CONSTRAINT fk_purchases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL,
    quantity DECIMAL(12,4) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    sold_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT NULL,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id),
    CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description VARCHAR(180) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    expense_date DATE NOT NULL,
    user_id INT NULL,
    purchase_id INT NULL,
    CONSTRAINT fk_expenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_expenses_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
);

ALTER TABLE ingredients           MODIFY quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
                                  MODIFY reorder_level DECIMAL(12,4) NOT NULL DEFAULT 5;
ALTER TABLE menu_item_ingredients MODIFY quantity DECIMAL(12,4) NOT NULL;
ALTER TABLE purchases             MODIFY quantity DECIMAL(12,4) NOT NULL;
ALTER TABLE sales                 MODIFY quantity DECIMAL(12,4) NOT NULL;

-- Add grams_per_piece only if it is missing (no "duplicate column" error)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'ingredients'
                   AND COLUMN_NAME = 'grams_per_piece');
SET @ddl := IF(@has_col = 0,
               'ALTER TABLE ingredients ADD COLUMN grams_per_piece DECIMAL(10,2) NULL AFTER unit',
               'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- purchases.user_id: who recorded it (added only if missing)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'user_id');
SET @q := IF(@c = 0, 'ALTER TABLE purchases ADD COLUMN user_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @f := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND CONSTRAINT_NAME = 'fk_purchases_user');
SET @q := IF(@f = 0, 'ALTER TABLE purchases ADD CONSTRAINT fk_purchases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales.user_id: who recorded it (added only if missing)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'user_id');
SET @q := IF(@c = 0, 'ALTER TABLE sales ADD COLUMN user_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @f := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND CONSTRAINT_NAME = 'fk_sales_user');
SET @q := IF(@f = 0, 'ALTER TABLE sales ADD CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- expenses.user_id: who recorded it (added only if missing)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'user_id');
SET @q := IF(@c = 0, 'ALTER TABLE expenses ADD COLUMN user_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @f := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND CONSTRAINT_NAME = 'fk_expenses_user');
SET @q := IF(@f = 0, 'ALTER TABLE expenses ADD CONSTRAINT fk_expenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- expenses.purchase_id: the restock purchase that caused this expense (NULL for rent, gas, etc.)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'purchase_id');
SET @q := IF(@c = 0, 'ALTER TABLE expenses ADD COLUMN purchase_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @f := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND CONSTRAINT_NAME = 'fk_expenses_purchase');
SET @q := IF(@f = 0, 'ALTER TABLE expenses ADD CONSTRAINT fk_expenses_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @q; EXECUTE stmt; DEALLOCATE PREPARE stmt;
