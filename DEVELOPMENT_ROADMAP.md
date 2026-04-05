# 📋 Photowooshop Plugin - Fejlesztési Roadmap

**Last Updated**: 2026. április 5.  
**Current Version**: 1.1.27  
**Status**: Production Ready (with staging validation required)

---

## 🚨 KRITIKUS - 24 órán belül (Security & Stability)

### 1. ✅ Security Hardening - Nonce & Validation
- **Priority**: P0 KRITIKUS  
- **Effort**: 2 óra  
- **Impact**: HIGH - RCE/CSRF risk csökkentés  
- **Status**: NOT STARTED

**Feladatok:**
- [ ] Per-action nonce-ok implementálása (nem shared `photowooshop_admin_nonce`)
  - `wp_verify_nonce($_REQUEST['nonce'], 'photowooshop_action_' . $action)`
- [ ] Image validation EXIF/metadata check hozzáadása
  ```php
  function validate_image_deep($data) {
      $image = @imagecreatefromstring($data);
      if (!$image) return false;
      imagedestroy($image);
      return true;
  }
  ```
- [ ] File path traversal validation (basename() helyett whitelist)
- [ ] Rate limiting per user ID (nem csak IP)
- [ ] Upload nonce timestamp check (5 perces window)

**Tesztelés:**
- [ ] OWASP top 10 manual security audit
- [ ] Browser dev tools: POST request nonce validation
- [ ] Test large base64 image → rejection

**Dokumentáció:**
- [ ] Security best practices note в README.md

---

### 2. ⚠️ Logging & Error Monitoring System
- **Priority**: P0 KRITIKUS  
- **Effort**: 3 óra  
- **Impact**: CRITICAL - debugging production issues  
- **Status**: NOT STARTED

**Feladatok:**
- [ ] Custom logs tábla: `photowooshop_logs`
  - Fields: id, timestamp, level (error/warn/info), action, user_id, order_id, message, context, trace
- [ ] Helper `log_action()` function
- [ ] Metrics tracking option: `photowooshop_metrics`
  - uploads_total, uploads_failed, avg_upload_time_ms, avg_render_time_ms
- [ ] WP-CLI commands
  - `wp photowooshop logs list --level=error`
  - `wp photowooshop metrics`
- [ ] Admin page: Logs részlet (Latest 50 errors)
- [ ] Sentry/CloudWatch integration hook (opcionális)

**Tesztelés:**
- [ ] Szándékos error → log feljelenítés
- [ ] Metrics pontossága
- [ ] WP-CLI command működés

**Dokumentáció:**
- [ ] Troubleshooting guide.md

---

## 🔴 MAGAS PRIORITÁS - 1-2 hét (Performance & UX)

### 3. ⚡ Performance: Material List Query Optimization
- **Priority**: P1 HIGH  
- **Effort**: 3-4 óra  
- **Impact**: MEDIUM - 5-6x faster admin list (1000+ orders esetén)  
- **Status**: NOT STARTED

**Current Issue**: `get_photowooshop_orders_page()` iterates ALL orders, then filters → O(n)

**Feladatok:**
- [ ] WC_Order_Query helyett direct WP_Query с meta_query
  ```php
  $query = new WC_Order_Query([
      'limit' => $per_page + 1,
      'meta_query' => [
          ['relation' => 'OR',
              ['key' => '_photowooshop_montage_url', 'compare' => 'EXISTS'],
              ['key' => '_photowooshop_individual_images', 'compare' => 'EXISTS'],
          ]
      ]
  ]);
  ```
- [ ] Index cache use при gyors számlálás
- [ ] Search filter optimization (product title by meta)
- [ ] Pagination fix (has_next_page logic)

**Tesztelés:**
- [ ] Load test: 1000+ orders → load time mérés
- [ ] Before/after: 5s vs 800ms
- [ ] Keresés és szűrés működés

**Dokumentáció:**
- [ ] Performance improvements note

---

### 4. 🎨 Admin UI/UX Enhancements
- **Priority**: P1 HIGH  
- **Effort**: 4-5 óra  
- **Impact**: MEDIUM - daily workflow 2x gyorsabb  
- **Status**: NOT STARTED

**Feladatok:**
- [ ] Filter persistence: user meta `photowooshop_list_filters` mentése
- [ ] Bulk actions (select all, bulk repair, bulk delete)
  - WP List Table API használata
- [ ] Dark mode support (CSS variables)
- [ ] Dashboard widget: Today stats (uploads, pending repairs)
- [ ] Resource limits settings panel
  - Max image size (MB)
  - Total storage quota (MB)
  - Cleanup age (days)
- [ ] Column sorting (date, customer name)
- [ ] Inline action expandable details (hover popup)

**Tesztelés:**
- [ ] Filter save/load cycles
- [ ] Bulk actions: 50+ item처리
- [ ] Dark mode toggle
- [ ] Storage limit enforcement

**Dokumentáció:**
- [ ] User guide: bulk operations

---

### 5. 🔧 Incremental Index & Performance: Font Cache
- **Priority**: P1 HIGH  
- **Effort**: 2-3 óra  
- **Impact**: MEDIUM - faster page load, less disk I/O  
- **Status**: NOT STARTED

**Feladatok:**
- [ ] Font cache transient (24h): `get_dynamic_fonts_cached()`
- [ ] Incremental index cron job: only new/modified orders
  - `photowooshop_incremental_index` hook (daily)
  - Last scan timestamp tracking
  - Batch processing (500 orders per run)
- [ ] Cleanup background processing: async file deletion
  - WP_Background_Processing class
  - Batch 100 files per job

**Tesztelés:**
- [ ] Font cache hit rate
- [ ] Incremental index vs full scan (time comparison)
- [ ] Background job queue status

---

## 🟠 KÖZEPES PRIORITÁS - 2-3 hét (Features & Integrations)

### 6. 📊 REST API Support
- **Priority**: P2 MEDIUM  
- **Effort**: 6-8 óra  
- **Impact**: HIGH - headless, mobile app, B2B portal support  
- **Status**: NOT STARTED

**Endpoints:**
- [ ] GET `/wp-json/photowooshop/v1/templates` - List templates
- [ ] POST `/wp-json/photowooshop/v1/design` - Save design
- [ ] GET `/wp-json/photowooshop/v1/orders/{order_id}/materials` - Order materials
- [ ] POST `/wp-json/photowooshop/v1/upload-image` - Upload image
- [ ] GET `/wp-json/photowooshop/v1/health` - Health check

**Tesztelés:**
- [ ] Postman/REST client test
- [ ] Authentication/permission checks
- [ ] Rate limiting on endpoints

**Dokumentáció:**
- [ ] REST API docs (OpenAPI spec)

---

### 7. 🔗 Third-Party Integrations
- **Priority**: P2 MEDIUM  
- **Effort**: 8-10 óra (całość) / 2-3 óra (per integration)  
- **Impact**: MEDIUM-HIGH - new features/revenue  
- **Status**: NOT STARTED

#### 7.1 Printful Print-on-Demand
- [ ] Printful API client setup
- [ ] `sync_to_printful($order_id)` function
- [ ] Order hook: send montage to print vendor
- [ ] Status sync: print status → WC order note
- **Effort**: 3 óra

#### 7.2 S3/Backblaze B2 Backup
- [ ] AWS SDK integration
- [ ] Daily backup cron: `photowooshop_daily_backup`
- [ ] Manual backup button (admin)
- [ ] Restore option (manual only)
- **Effort**: 2 óra

#### 7.3 Slack Notifications
- [ ] Error webhook integration
- [ ] High-volume upload alert (daily threshold)
- [ ] Settings: slack_webhook_url
- **Effort**: 1 óra

#### 7.4 Google Analytics 4 Events
- [ ] Event: `photowooshop_editor_opened`
- [ ] Event: `photowooshop_design_saved`
- [ ] Event: `photowooshop_zip_downloaded`
- [ ] GA4 gtag() integration
- **Effort**: 1.5 óra

---

### 8. 📧 Email Notifications
- **Priority**: P2 MEDIUM  
- **Effort**: 2-3 óra  
- **Impact**: MEDIUM - better customer experience  
- **Status**: NOT STARTED

**Feladatok:**
- [ ] WC_Email class: "Design Ready" notification
- [ ] Email template: confirmation + file list + download link
- [ ] Trigger: order completed + materials uploaded
- [ ] Settings: enable/disable per template
- [ ] Test email button (admin)

**Tesztelés:**
- [ ] Email delivery test (localhost)
- [ ] Template rendering

---

### 9. 🎛️ Admin Diagnostics Enhancement
- **Priority**: P2 MEDIUM  
- **Effort**: 2-3 óra  
- **Impact**: MEDIUM - easier troubleshooting  
- **Status**: NOT STARTED

**Feladatok:**
- [ ] Disk usage breakdown (montages vs audio vs orphans)
- [ ] Material reference counter (how many orders use file)
- [ ] Template usage stats (most used template)
- [ ] Upload quality report (failed uploads per day, week)
- [ ] Error heat map (product categories with issues)
- [ ] Health check endpoint: WP-JSON endpoint

**Tesztelés:**
- [ ] Stats accuracy
- [ ] Health check endpoint response

---

## 🟡 ALACSONY PRIORITÁS - Nice to Have (Long-term)

### 10. 🎛️ CSV Export & Bulk Processing
- **Priority**: P3 LOW  
- **Effort**: 2-3 óra  
- **Impact**: LOW-MEDIUM - power users only  
- **Status**: NOT STARTED

**Feladatok:**
- [ ] CSV export: order ID, customer, materials list, upload date
- [ ] WP-CLI batch command: `wp photowooshop process-orders <date-range>`
- [ ] Repair multiple orders at once

---

### 11. 🔐 GDPR Data Deletion & Privacy
- **Priority**: P3 LOW  
- **Effort**: 2 óra  
- **Impact**: MEDIUM - legal requirement  
- **Status**: NOT STARTED

**Feladatos:**
- [ ] `wp_privacy_personal_data_erased` hook
- [ ] Delete user's uploaded montages on account deletion
- [ ] Privacy export: include material metadata

---

### 12. 🎓 Frontend Enhancements
- **Priority**: P3 LOW  
- **Effort**: 4-5 óra  
- **Impact**: LOW-MEDIUM - UX improvement  
- **Status**: NOT STARTED

**Feladatos:**
- [ ] Mobile responsiveness (touch events, Hammer.js)
- [ ] Undo/Redo stack (IndexedDB/localStorage, max 10 steps)
- [ ] Guided tutorial (Intro.js / Shepherd.js)
- [ ] Font preview in dropdown
- [ ] Auto-save draft (localStorage backup)

---

## 📅 AJÁNLOTT MEGVALÓSÍTÁSI TERV

```
1. HÉT (KRITIKUS - SECURITY & STABILITY):
├─ Nap 1: Security hardening (nonce, image validation)
└─ Nap 2: Logging system + Metrics

2. HÉT (PERFORMANCE & CORE UX):
├─ Nap 3-4: Query optimization (material list)
├─ Nap 5: Admin UX enhancements
└─ Nap 6: Incremental index + Font cache

3. HÉT (FEATURES & INTEGRATIONS):
├─ Nap 7-9: REST API endpoints
└─ Nap 10: Integrations (start with Printful or Slack)

4+ HÉT (NICE-TO-HAVE & POLISH):
├─ CSV/Bulk processing
├─ GDPR compliance
├─ Frontend enhancements
└─ Performance tuning
```

---

## 🧪 STAGING VALIDATION (kiindulási pont)

Ezek KÖTELEZŐ az 1.1.27 élesítése előtt:

- [ ] Admin smoke test futtatása (Diagnostics panel)
- [ ] Materials lista betöltés safe-mode be/ki
- [ ] Per-order Javítás gombra kattintás
- [ ] Modal + ZIP download egy valós rendelésen
- [ ] Error log tiszta (3 nap után)
- [ ] 1.1.26 → 1.1.27 update folyamat (staging-en)

---

## 📝 CHANGELOG TEMPLATE

Minden release-hez:

```markdown
## [X.X.X] - YYYY-MM-DD

### Added
- [Feature]: Brief description

### Fixed
- [Bug]: Brief description

### Changed
- [Performance]: Brief description

### Security
- [Vulnerability]: Brief description
```

---

## 🔗 ÚTMUTATÓK

- **Git Workflow**: Commit message format `[Category] Description`
  - Examples: `[Security] Fix CSRF nonce collision`
  - Examples: `[Performance] Optimize material list query`

- **Testing**: Minden feature + unit test + integration test

- **Documentation**: Update README.md és inline comments

---

**Next Action**: Pick task from KRITIKUS section, implement + test, git commit + push to GitHub, then move to next.
