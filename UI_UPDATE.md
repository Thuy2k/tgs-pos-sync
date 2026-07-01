# UI Update - TGS POS Sync Status Page

## Changes Made

### 1. Removed "Push lên Hub" Button
**Before:**
```
[Push lên Hub] [Pull từ Hub] [Push + Pull]
```

**After:**
```
[Kéo về & Đẩy lên (LOCAL)] [Pull từ Hub (GLOBAL)]
```

**Reason:** 
- Không ai push trước nữa - luôn phải pull local tables trước khi push
- Push button cũ không có conflict resolution
- Tránh nhầm lẫn giữa LOCAL và GLOBAL sync

### 2. Renamed "Push + Pull" → "Kéo về & Đẩy lên (LOCAL)"

**New button label:** `Kéo về & Đẩy lên (LOCAL)`

**Functionality:**
1. Pull local tables từ Hub về (ledger, ledger_item, ledger_meta)
2. Auto-resolve conflicts nếu có
3. Push pending events lên Hub

**Success message format:**
```
Sync LOCAL thành công!
Kéo về: X records.
Giải quyết: Y conflicts.
Đẩy lên: Z events, Accepted: A.
```

### 3. Renamed "Pull từ Hub" → "Pull từ Hub (GLOBAL)"

**New button label:** `Pull từ Hub (GLOBAL)`

**Functionality:** Pull GLOBAL tables (categories, products, policies, lots)

**Success message format:**
```
Pull GLOBAL thành công! X records trong Y batch(es)
```

### 4. Updated Cron Schedule Description

**Before:**
```
- Push lên Hub: Mỗi 5 phút
- Pull từ Hub: Mỗi 10 phút
```

**After:**
```
- Kéo về & Đẩy lên LOCAL: Mỗi 5 phút (Pull local tables → Push events)
- Pull GLOBAL từ Hub: Mỗi 10 phút (Categories, Products, Policies, Lots)
```

## User Experience Improvements

### Clarity
- ✅ Rõ ràng phân biệt LOCAL vs GLOBAL sync
- ✅ User hiểu rằng push luôn đi kèm pull local
- ✅ Tránh confusion về "push trước" hay "pull trước"

### Safety
- ✅ Conflict resolution tự động
- ✅ Không thể push mà không pull local trước
- ✅ Data integrity được đảm bảo

### Performance
- ✅ Giảm từ 3 buttons xuống 2 buttons (UI cleaner)
- ✅ Mỗi button có mục đích rõ ràng
- ✅ Cron schedule hợp lý: LOCAL sync 5 phút, GLOBAL sync 10 phút

## Technical Implementation

### Button Click Flow

**"Kéo về & Đẩy lên (LOCAL)" button:**
```javascript
$.ajax({
    action: 'tgs_pos_manual_push', // Reuse push action (already has pull logic)
    success: function(response) {
        var pulled = response.data.pulled || 0;
        var conflicts = response.data.conflicts_resolved || 0;
        var pushed = response.data.pushed || 0;
        var accepted = response.data.applied.length || 0;
        
        showResult('Sync LOCAL thành công! ...');
    }
});
```

**Backend (class-push-collector.php):**
```php
public static function push() {
    // STEP 1: Pull local tables (conflict prevention)
    $pull_result = TGS_POS_Pull_Handler::pull_local_tables();
    
    // STEP 2: Push pending events
    // ...
    
    return [
        'pulled' => $pull_result['pulled'],
        'conflicts_resolved' => $pull_result['conflicts_resolved'],
        'pushed' => count($ready_events),
        'applied' => $result['data']['accepted'],
    ];
}
```

### Files Modified

1. ✅ `admin/views/status.php` - UI buttons & JavaScript
2. ✅ `includes/class-push-collector.php` - Already has pull logic
3. ✅ `includes/class-pull-handler.php` - NEW: Conflict resolution

### Testing Checklist

- [ ] Click "Kéo về & Đẩy lên (LOCAL)" → Should show pulled, conflicts, pushed
- [ ] Click "Pull từ Hub (GLOBAL)" → Should show categories, products, policies, lots
- [ ] Cron job runs every 5 min → LOCAL sync executes
- [ ] Cron job runs every 10 min → GLOBAL sync executes
- [ ] Conflict scenario: Hub has ID=56, Local creates ID=56 → Auto-resolve to new ID
- [ ] Success messages display correctly
- [ ] Page reloads after 2 seconds

## Screenshots (Expected)

### Before
```
┌─────────────────────────────────────────────────┐
│ Đồng bộ thủ công                                │
│                                                 │
│ [Push lên Hub] [Pull từ Hub] [Push + Pull]     │
└─────────────────────────────────────────────────┘
```

### After
```
┌─────────────────────────────────────────────────┐
│ Đồng bộ thủ công                                │
│                                                 │
│ [Kéo về & Đẩy lên (LOCAL)]                      │
│ [Pull từ Hub (GLOBAL)]                          │
└─────────────────────────────────────────────────┘
```

## Date
2026-07-02
