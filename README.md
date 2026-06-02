# Animal Bite Center (ABC) — Integrated Queueing System
## City Health Office Davao | OJT Project

---

## 📁 File Structure

```
abc-system/
├── config.php              ← Database connection
├── database.sql            ← Run this in phpMyAdmin first
├── api/
│   ├── register.php        ← Kiosk: register patient, get ticket
│   ├── queue.php           ← Staff: view + manage queue
│   └── now_serving.php     ← TV: polling endpoint
└── pages/
    ├── kiosk.html          ← Touchscreen kiosk (patient self-register)
    ├── staff.html          ← Staff monitor dashboard
    └── tv.html             ← TV display (now serving)
```

---

## ⚙️ Setup Instructions (XAMPP)

### Step 1 — Copy files
Copy the entire `abc-system/` folder into:
```
C:\xampp\htdocs\abc-system\
```

### Step 2 — Start XAMPP
Open XAMPP Control Panel → Start **Apache** and **MySQL**

### Step 3 — Create the database
1. Open your browser → go to `http://localhost/phpmyadmin`
2. Click **Import** (top menu)
3. Choose the file `abc-system/database.sql`
4. Click **Go**

### Step 4 — Open the system
Open three separate browser windows/tabs:

| Page         | URL                                              | Device         |
|-------------|--------------------------------------------------|----------------|
| Kiosk       | `http://localhost/abc-system/pages/kiosk.html`  | Tablet/Touchscreen |
| Staff Monitor | `http://localhost/abc-system/pages/staff.html` | Staff laptop   |
| TV Display  | `http://localhost/abc-system/pages/tv.html`     | TV (F11 fullscreen) |

---

## 🖥️ How Each Page Works

### Kiosk (kiosk.html)
- Patient touches screen → selects type → confirms → gets ticket number
- Ticket types: **P001** (Priority) · **R001** (Regular) · **F001** (Follow-up)
- Auto-resets to idle after 12 seconds

### Staff Monitor (staff.html)
- Live queue list auto-refreshes every 3 seconds
- **Call Next** → system picks Priority first, then by arrival time (FIFO)
- **Mark Done** → removes patient from inside count (opens slot for next batch)
- **Skip** → removes patient if no-show
- **⚠ Sev** → set triage severity (Cat I / Cat II / Cat III)
  - Cat III patients are **automatically bumped to the front**
- **Reset Day** → clears queue for next morning

### TV Display (tv.html)
- Polls every 2.5 seconds for real-time updates
- Shows: Now Serving number (large), Next in line, waiting counts
- Press **F11** for fullscreen on the TV

---

## 🏥 Queue Logic Summary

```
IF patient type = Follow-up (F)
  → Skip regular steps → Go directly to encoder's counter

ELSE IF patient type = Priority (P) or Regular (R)
  → IF inside count < 5
      → Priority patients seated first
      → Remaining slots filled by Regular in arrival order
    ELSE
      → Wait outside until a slot opens

  → Triage → ITR → Vital Signs → Yellow Chair → Doctor
  → Encoder's Counter → Vaccination → Release

IF severity = Cat III (severe bite)
  → Bumped to front of queue regardless of arrival time
```

---

## 🔧 Troubleshooting

| Problem | Fix |
|---------|-----|
| "Database connection failed" | Make sure MySQL is running in XAMPP |
| Pages show blank / errors | Check Apache is running; verify file path in htdocs |
| Kiosk not generating tickets | Open browser console (F12) → check network tab for API errors |
| TV not updating | Check `api/now_serving.php` is reachable |
| Queue not resetting | Use "Reset Day" button on staff monitor, or run `CALL reset_daily();` in phpMyAdmin |

---

## 📌 Daily Operation

1. Morning: Click **Reset Day** on staff monitor to start fresh counters
2. Kiosk: Set to fullscreen on the tablet at the entrance
3. TV: Press F11 for fullscreen, face the waiting area
4. Staff monitor: Keep open on the staff laptop throughout the day

---

*Developed for OJT — Animal Bite Center, City Health Office Davao*
