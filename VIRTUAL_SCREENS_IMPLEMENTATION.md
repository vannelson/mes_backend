# Virtual Screen / Digital Signage Module - Implementation Summary

## Overview
A complete digital signage system for the MES application that allows users to create virtual screens with playlists containing URLs, widgets, images, and PDFs, then share them via public player links.

---

## Backend Implementation (✅ COMPLETE)

### Database Schema

#### 1. `virtual_screens` Table
- **Purpose**: Store virtual screen configurations
- **Key Fields**:
  - `id`, `user_id`, `name`, `description`
  - `share_token` (unique, unguessable)
  - `orientation` (landscape/portrait)
  - `aspect_ratio`, `timezone`, `refresh_interval`
  - `is_active`, `settings` (JSON)
  - Timestamps

#### 2. `playlist_items` Table
- **Purpose**: Store playlist items for each screen
- **Key Fields**:
  - `id`, `virtual_screen_id`
  - `type` (url, widget, image, pdf)
  - `content` (JSON - stores item-specific data)
  - `duration`, `order`
  - `schedule_start`, `schedule_end`
  - `is_active`
  - Timestamps

#### 3. `screen_media` Table
- **Purpose**: Track uploaded media files
- **Key Fields**:
  - `id`, `virtual_screen_id`
  - `filename`, `original_name`, `mime_type`
  - `size`, `path`
  - Timestamps

### Architecture

```
Controllers → Services → Repositories → Models → Database
```

**Pattern**: Repository Pattern with Service Layer

### API Endpoints

#### Authenticated Routes (require Bearer token)

**Virtual Screens:**
- `GET /api/v1/virtual-screens` - List user's screens
- `POST /api/v1/virtual-screens` - Create screen
- `GET /api/v1/virtual-screens/{id}` - Get screen detail
- `PUT /api/v1/virtual-screens/{id}` - Update screen
- `DELETE /api/v1/virtual-screens/{id}` - Delete screen
- `POST /api/v1/virtual-screens/{id}/toggle-active` - Toggle active status
- `POST /api/v1/virtual-screens/{id}/regenerate-token` - Regenerate share token

**Playlist Items:**
- `GET /api/v1/virtual-screens/{screenId}/playlist-items` - List items
- `POST /api/v1/playlist-items` - Create item
- `PUT /api/v1/playlist-items/{id}` - Update item
- `DELETE /api/v1/playlist-items/{id}` - Delete item
- `POST /api/v1/virtual-screens/{screenId}/playlist-items/reorder` - Reorder items
- `POST /api/v1/playlist-items/{id}/toggle-active` - Toggle item active

**Media:**
- `GET /api/v1/virtual-screens/{screenId}/media` - List media
- `POST /api/v1/virtual-screens/{screenId}/media` - Upload media (multipart/form-data)
- `GET /api/v1/screen-media/{id}` - Get media detail
- `DELETE /api/v1/screen-media/{id}` - Delete media

#### Public Routes (no auth, rate-limited)

- `GET /api/v1/public/screens/{shareToken}` - Get playlist for player (60 req/min)

### Security Features

1. **Authentication**: All management endpoints require Sanctum authentication
2. **Authorization**: Ownership verification on all operations
3. **Rate Limiting**: Public player endpoint limited to 60 requests/minute per IP
4. **File Validation**:
   - Max size: 10MB
   - Allowed types: JPEG, PNG, GIF, WebP, PDF
   - Image dimension check: Max 4K (3840x2160)
5. **Unguessable Tokens**: 64-character random share tokens
6. **Caching**: Public playlist cached for 5 minutes

### File Storage

- **Location**: `storage/app/public/virtual-screens/{screen_id}/`
- **Access**: Via public URL through storage symlink
- **Cleanup**: Automatic file deletion when media record is deleted

---

## Frontend Implementation (🚧 IN PROGRESS)

### Redux Store

**Slice**: `virtualScreensSlice.js`
- **State**: screens, currentScreen, playlistItems, media, loading, error
- **Actions**: CRUD operations for screens, items, and media
- **Async Thunks**: API integration with error handling

### API Service

**File**: `virtualScreenService.js`
- Axios-based API client
- Bearer token authentication
- Multipart form data support for uploads
- Public player endpoint (no auth)

### Pages (To Be Created)

1. **VirtualScreens.jsx** - List/manage screens
2. **VirtualScreenEditor.jsx** - Edit screen and playlist
3. **VirtualScreenPlayer.jsx** - Public full-screen player

### Components (To Be Created)

**Playlist Management:**
- PlaylistBuilder.jsx - Drag-and-drop playlist editor
- AddItemModal.jsx - Add URL/widget/media items
- WidgetConfigurator.jsx - Configure widget settings

**Media:**
- MediaLibrary.jsx - Browse and manage uploaded media

**Player:**
- PlayerItem.jsx - Render individual playlist items
- TimeWidget.jsx - Display current time
- DateWidget.jsx - Display current date
- WeatherWidget.jsx - Display weather info
- TickerWidget.jsx - Scrolling text ticker

---

## Data Flow

### Creating a Virtual Screen

```
User → VirtualScreens Page → Create Button → Form
  ↓
Redux Action (createScreen)
  ↓
API Service → POST /api/v1/virtual-screens
  ↓
Backend: Controller → Service → Repository → Model → Database
  ↓
Response with screen data (including share_token)
  ↓
Redux State Updated → UI Refreshed
```

### Playing a Screen

```
Public URL: /player/{shareToken}
  ↓
VirtualScreenPlayer Component
  ↓
API Call: GET /api/v1/public/screens/{shareToken} (no auth)
  ↓
Backend: PublicScreenController → Service (cached) → Repository → Database
  ↓
Returns: screen settings + ordered playlist items
  ↓
Player renders items sequentially with transitions
```

---

## Widget Types

### 1. URL Widget
```json
{
  "type": "url",
  "content": {
    "url": "https://example.com"
  },
  "duration": 30
}
```

### 2. Time Widget
```json
{
  "type": "widget",
  "content": {
    "widget_type": "time",
    "format": "12h",
    "timezone": "America/New_York"
  },
  "duration": 10
}
```

### 3. Date Widget
```json
{
  "type": "widget",
  "content": {
    "widget_type": "date",
    "format": "MMMM DD, YYYY",
    "locale": "en-US"
  },
  "duration": 10
}
```

### 4. Weather Widget
```json
{
  "type": "widget",
  "content": {
    "widget_type": "weather",
    "location": "New York, NY",
    "units": "imperial"
  },
  "duration": 15
}
```

### 5. Ticker Widget
```json
{
  "type": "widget",
  "content": {
    "widget_type": "ticker",
    "text": "Important announcement...",
    "speed": "medium",
    "direction": "left"
  },
  "duration": 20
}
```

### 6. Image/PDF
```json
{
  "type": "image",
  "content": {
    "media_id": 123,
    "url": "https://example.com/storage/..."
  },
  "duration": 10
}
```

---

## Next Steps

### Immediate (Frontend Pages)
1. ✅ Create Redux slice and API service
2. ⏳ Create VirtualScreens list page
3. ⏳ Create VirtualScreenEditor page
4. ⏳ Create VirtualScreenPlayer page
5. ⏳ Create supporting components

### Testing
- [ ] Run backend migrations
- [ ] Test API endpoints with Postman/Insomnia
- [ ] Test file uploads
- [ ] Test public player
- [ ] Test scheduling logic
- [ ] Test rate limiting

### Optimization
- [ ] Add CDN support for media
- [ ] Implement service worker for offline player
- [ ] Add analytics/view tracking
- [ ] Implement playlist templates

---

## Files Created

### Backend (31 files)
- 3 Migrations
- 3 Models
- 6 Repository files (3 interfaces + 3 implementations)
- 6 Service files (3 interfaces + 3 implementations)
- 4 Controllers
- 6 Validation Request classes
- 1 Middleware
- 1 Routes file (modified)
- 1 Service Provider (modified)

### Frontend (3 files so far)
- 1 Redux slice
- 1 API service
- 1 Store configuration (modified)

**Total**: 34 files created/modified

---

## Usage Example

### Creating and Sharing a Screen

```javascript
// 1. Create screen
const screen = await dispatch(createScreen({
  name: "Lobby Display",
  orientation: "landscape",
  aspect_ratio: "16:9"
}));

// 2. Add playlist items
await dispatch(createPlaylistItem({
  virtual_screen_id: screen.id,
  type: "widget",
  content: { widget_type: "time" },
  duration: 10,
  order: 0
}));

// 3. Share URL
const playerUrl = `${window.location.origin}/player/${screen.share_token}`;
```

### Player URL Format
```
https://your-domain.com/player/abc123def456...xyz789
```

---

**Status**: Backend Complete ✅ | Frontend In Progress 🚧
**Last Updated**: 2026-01-15
