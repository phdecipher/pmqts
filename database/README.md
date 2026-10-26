# PMQTS database

## Local XAMPP setup

1. Start MySQL in the XAMPP Control Panel.
2. Open phpMyAdmin, select **Import**, and import `database/schema.sql` (or run it with the XAMPP MySQL client).
3. If `tbl_inventory` already exists from the previous setup, also import/run `database/migrations/20261006_add_inventory_qr_tokens.sql`. This adds the unique QR token column without removing inventory rows.
4. The scripts create `pmqts`, `tbl_inventory`, `tbl_maintenance`, and `tbl_users`. They use `CREATE TABLE IF NOT EXISTS`, so existing tables and procurement rows are preserved. The existing `tbl_procurement` table is expected to contain `id`, `pr_number`, `item_name`, `source_trigger`, `estimated_cost`, `status`, and `created_at`.
5. Open `http://localhost/pmqts/pages/setup_admin.php` and create the first administrator. The setup page stops accepting account creation after the first user is added. Passwords are stored as PHP password hashes.
6. Sign in at `http://localhost/pmqts/pages/login.php`. Dashboard, inventory, maintenance, procurement, QR label, QR scanner, and equipment record pages require authentication.
7. By default the PHP app connects to `localhost:3306` as `root` with an empty password and database `pmqts`. Override with `PMQTS_DB_HOST`, `PMQTS_DB_PORT`, `PMQTS_DB_USER`, `PMQTS_DB_PASSWORD`, and `PMQTS_DB_NAME` environment variables if your local credentials differ.

Inventory and maintenance CRUD, procurement requests/approval, dashboard metrics, and sign-in use MySQL. Procurement approval inserts an inventory row and changes the request status in one transaction. QR labels point to asset records using persistent random tokens; the scanner accepts a code only when its token exists on an active inventory asset.
