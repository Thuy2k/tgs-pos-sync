# TGS POS Sync Plugin

Plugin WordPress cho máy Local POS. Đồng bộ dữ liệu với Hub trung tâm theo kiến trúc Local-First.

## Tổng quan

- **Vai trò**: Local POS Client
- **Kiến trúc**: Local-First (ghi local trước, sync sau)
- **Giao thức**: REST API qua HTTPS
- **Xác thực**: QR Code → Token-based
- **Sync**: Tự động mỗi 5-10 phút (có thể trigger thủ công)

## Cài đặt

### 1. Copy plugin vào máy Local
```bash
# Docker hoặc máy Local
cp -r tgs-pos-sync/ /var/www/html/wp-content/plugins/
```

### 2. Kích hoạt plugin
```
WordPress Admin → Plugins → Activate "TGS POS Sync"
```

### 3. Đăng ký với Hub

1. **Lấy QR Code từ Hub**
   - Vào Hub Network Admin → TGS Hub → Cửa hàng
   - Click "Tạo QR mới" cho blog_id tương ứng
   - Copy dữ liệu JSON từ QR Code

2. **Quét/Nhập QR Code trên Local**
   - Vào Local Admin → POS Sync → Cài đặt
   - Dán JSON vào textarea
   - Click "Đăng ký"

3. **Xác nhận kết nối**
   - Sau khi đăng ký thành công, trang sẽ reload
   - Bạn sẽ thấy trạng thái "Đã kết nối với Hub"

## Cấu trúc Plugin

```
tgs-pos-sync/
├── tgs-pos-sync.php                # Main plugin file
├── includes/
│   ├── class-database.php          # Tạo 4 bảng sync
│   ├── class-config.php            # Quản lý config (hub_url, token)
│   ├── class-http-client.php       # Gọi REST API lên Hub
│   ├── class-qr-scanner.php        # Đăng ký bằng QR Code
│   ├── class-event-logger.php      # Log events vào outbox
│   ├── class-id-mapper.php         # Map Local ID ↔ Hub ID
│   ├── class-push-collector.php    # Thu thập và push events
│   ├── class-pull-applier.php      # Pull và apply changes
│   └── class-sync-engine.php       # Điều phối Push/Pull
└── admin/
    ├── class-settings-page.php     # Trang cài đặt
    ├── class-sync-status.php       # Trang trạng thái
    └── views/
        ├── settings.php            # Giao diện đăng ký QR
        └── status.php              # Giao diện theo dõi sync
```

## Database Schema

Plugin tạo 4 bảng sync:

### 1. `wp_tgs_sync_outbox` - Local→Hub
Chứa events chờ đẩy lên Hub (orders, customers)

```sql
Columns:
- event_id: Unique event ID
- event_type: order_created, customer_created, etc.
- table_name: wp_local_ledger, wp_local_ledger_person, etc.
- operation: INSERT, UPDATE, DELETE
- data: JSON payload
- status: pending → sent → acked
- retry_count, error_message
- created_at, sent_at, acked_at
```

### 2. `wp_tgs_sync_inbox` - Hub→Local
Chứa changes đã lấy về từ Hub (products, policies)

```sql
Columns:
- change_id: Unique change ID
- table_name: wp_global_product_name, etc.
- operation: INSERT, UPDATE, DELETE
- data: JSON payload
- version: Hub version number
- status: pending → applied
- error_message
- created_at, applied_at
```

### 3. `wp_tgs_sync_state` - Sync State
Lưu trạng thái sync (hub_url, token, last_push, last_pull)

```sql
Columns:
- state_key: hub_url, client_token, last_push_at, etc.
- state_value: Giá trị
```

### 4. `wp_tgs_id_map` - ID Mapping
Map Local ID ↔ Hub ID (để resolve references)

```sql
Columns:
- table_name: wp_local_ledger, etc.
- local_id: ID trên Local
- hub_id: ID trên Hub
```

## Workflow Sync

### Local→Hub (Push)

1. **Ghi event vào Outbox**
   ```php
   TGS_POS_Event_Logger::log_order_created($order_data);
   ```

2. **Cron tự động push** (mỗi 5 phút)
   ```php
   do_action('tgs_pos_sync_push');
   ```

3. **Hoặc trigger thủ công**
   - Admin → POS Sync → Trạng thái Sync → Click "Push lên Hub"

4. **Push flow**
   - Lấy pending events từ outbox
   - Gọi `POST /wp-json/tgs-hub/v1/sync/push`
   - Mark events as `sent`
   - Gửi ACK
   - Mark events as `acked`

### Hub→Local (Pull)

1. **Cron tự động pull** (mỗi 10 phút)
   ```php
   do_action('tgs_pos_sync_pull');
   ```

2. **Hoặc trigger thủ công**
   - Admin → POS Sync → Trạng thái Sync → Click "Pull từ Hub"

3. **Pull flow**
   - Gọi `GET /wp-json/tgs-hub/v1/sync/pull?since_version=123`
   - Nhận changes từ Hub
   - Lưu vào inbox
   - Apply changes vào local database
   - Mark as `applied`
   - Gửi ACK
   - Update `last_pull_version`

## API Usage

### Đăng ký với Hub
```php
$result = TGS_POS_HTTP_Client::register($hub_url, $setup_token);
// Returns: client_token, blog_id, store_id
```

### Push events
```php
$result = TGS_POS_HTTP_Client::push($events);
// Returns: applied count, failed count
```

### Pull changes
```php
$result = TGS_POS_HTTP_Client::pull($since_version);
// Returns: changes[], latest_version
```

### Send ACK
```php
$result = TGS_POS_HTTP_Client::ack($synced_event_ids, $applied_change_ids);
```

## Logging Events

### Log Order Created
```php
$order_data = array(
    'id' => 123,
    'customer_id' => 456,
    'total' => 150000,
    // ... other fields
);

TGS_POS_Event_Logger::log_order_created($order_data);
```

### Log Customer Created
```php
$customer_data = array(
    'id' => 456,
    'name' => 'Nguyễn Văn A',
    'phone' => '0912345678',
    // ... other fields
);

TGS_POS_Event_Logger::log_customer_created($customer_data);
```

### Custom Event
```php
TGS_POS_Event_Logger::log_event(
    'product_updated',           // event_type
    'wp_local_product',          // table_name
    'UPDATE',                    // operation
    $data                        // data array
);
```

## Admin UI

### POS Sync → Cài đặt
- **Chưa đăng ký**: Form nhập QR Code JSON
- **Đã đăng ký**: Hiển thị Hub URL, Store ID, Blog ID
- Nút "Ngắt kết nối" để xóa registration

### POS Sync → Trạng thái Sync
- **Outbox stats**: Pending, Sent, Acked, Errors
- **Inbox stats**: Pending, Applied, Errors
- **Lần sync cuối**: Last push, Last pull
- **Trigger thủ công**: Push, Pull, Full Sync
- **Cron schedule**: Mỗi 5 phút (push), 10 phút (pull)

## Cron Schedule

Plugin tự động đăng ký 2 cron jobs:

```php
// Push mỗi 5 phút
wp_schedule_event(time(), 'every_5_minutes', 'tgs_pos_sync_push');

// Pull mỗi 10 phút
wp_schedule_event(time(), 'every_10_minutes', 'tgs_pos_sync_pull');
```

Để disable cron và chỉ dùng trigger thủ công:
```php
// Thêm vào wp-config.php
define('DISABLE_WP_CRON', true);
```

## Offline-First

Plugin hoạt động **offline-first**:
- Ghi dữ liệu vào local database trước
- Events được log vào outbox
- Khi có internet, cron tự động push
- Nếu push fail, retry tự động

## Phase 1 MVP - 3 bảng sync

### Local→Hub (Push)
- `wp_local_ledger` - Đơn hàng
- `wp_local_ledger_item` - Chi tiết đơn hàng
- `wp_local_ledger_person` - Khách hàng

### Hub→Local (Pull)
- `wp_global_product_name` - Sản phẩm
- `wp_global_product_cat` - Danh mục
- `wp_global_selling_policy` - Chính sách bán

## Troubleshooting

### Plugin không push được
1. Check registration: Admin → POS Sync → Cài đặt
2. Check outbox: Có events pending không?
3. Check Hub URL: Có đúng và accessible không?
4. Check client_token: Còn valid không?
5. Check logs: `wp-content/debug.log`

### Pull không nhận được data
1. Check `last_pull_version` trong database
2. Reset version về 0: `TGS_POS_Config::set('last_pull_version', '0');`
3. Trigger pull thủ công

### Events bị stuck ở "sent"
- Events "sent" nhưng chưa "acked" → ACK thất bại
- Retry bằng cách trigger push lại
- Check Hub logs để xem Hub có nhận được không

## Yêu cầu hệ thống

- WordPress 5.8+
- PHP 7.4+
- MySQL 5.7+ hoặc MariaDB 10.2+
- HTTPS (bắt buộc cho production)
- Hub URL phải accessible từ Local

## Docker Deployment

```dockerfile
FROM wordpress:latest

# Copy plugin
COPY tgs-pos-sync/ /var/www/html/wp-content/plugins/tgs-pos-sync/

# Enable plugin via WP-CLI
RUN wp plugin activate tgs-pos-sync --allow-root
```

## Liên hệ

- Website: https://tgsworld.vn
- Plugin Version: 1.0.0
