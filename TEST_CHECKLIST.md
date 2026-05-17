# Home-Start Volunteer Portal — Complete Test Checklist

## Prerequisites
- XAMPP running with Apache and MySQL active
- All files copied into `htdocs/HOMESTART/`
- PHP 7.4+ (XAMPP ships with 8.x — fine)

---

## STEP 1 — Database Setup

1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Click **Import** (top nav)
3. Choose file: `homestart.sql`
4. Click **Go**
5. **Verify**: In the left panel you should see the `homestart` database with these tables:
   - `audit_log`, `availability`, `qualification`, `skill`, `staff`, `transport`
   - `volunteer`, `volunteer_qualification`, `volunteer_skill`, `volunteer_transport`

**Check transport seeds:**
```sql
SELECT * FROM transport;
-- Expected: Walking (1), Cycling (2), Vehicle (3), Public Transport (4)
```

**Check skill seeds:**
```sql
SELECT * FROM skill;
-- Expected: 8 rows (Childcare, Cooking, Driving, First Aid, Counselling, Gardening, Administrative, Language Support)
```

**Check volunteer seeds:**
```sql
SELECT * FROM volunteer;
-- Expected: VOL001 (no profile), VOL002 (Jane Smith, full profile), VOL003 (Tom Jones, full profile)
```

---

## STEP 2 — Create Test Staff Account

1. Visit: `http://localhost/HOMESTART/create_staff.php`
2. You should see: "Staff account created / updated" with username `admin` and hash displayed
3. **Verify in DB:**
   ```sql
   SELECT staff_id, username FROM staff;
   -- Expected: 1 row, username = 'admin'
   ```
4. **DELETE `create_staff.php`** from the server after this step (security requirement)

---

## STEP 3 — Test Volunteer Login

### 3a. Basic flow (VOL001 — incomplete profile)
1. Visit: `http://localhost/HOMESTART/login.php`
2. Verify the CSRF hidden field is present in the page source (right-click → View Source, look for `name="csrf_token"`)
3. Enter **Volunteer ID**: `VOL001` and **Postcode**: `BN1 1AA`
4. Click Log In
5. **Expected**: Redirected to `profile_form.php` (because VOL001 has no forename yet)

### 3b. Wrong credentials
1. Go to `login.php`
2. Enter **Volunteer ID**: `VOL001` and **Postcode**: `WRONG`
3. **Expected**: Redirected back to `login.php?error=invalid` with message "Volunteer ID or postcode not recognised"
4. Repeat 5 times
5. On the 6th attempt: **Expected**: `login.php?error=locked` — "Too many failed attempts"
6. Wait 60 seconds and try again — throttle should reset

### 3c. Empty fields
1. Submit login form with blank postcode
2. **Expected**: `login.php?error=missing`

### 3d. Successful login (VOL002 — complete profile)
1. Enter **VOL002** / **BN2 2BB** → should go straight to `home.php`

---

## STEP 4 — Test Profile Form & Submission (VOL001)

### 4a. Access profile_form.php
1. Log in as VOL001 / BN1 1AA → you are redirected to `profile_form.php`
2. Verify the page shows:
   - Skills checkboxes pulled from DB (8 items)
   - Qualifications checkboxes from DB (5 items)
   - Transport checkboxes (exactly 4: Walking, Cycling, Vehicle, Public Transport)
   - Availability table with 7 rows (Monday–Sunday), each with checkbox + time inputs

### 4b. Submit with no transport (should fail)
1. Fill in forename, surname, valid DOB
2. Do NOT tick any transport checkbox
3. Click Save Profile
4. **Expected**: Redirected back to `profile_form.php?error=no_transport`

### 4c. Submit invalid date of birth
1. Fill forename, surname, but enter DOB: `99/99/9999`
2. **Expected**: `profile_form.php?error=invalid_dob`

### 4d. Submit future date of birth
1. Enter DOB: `01/01/2099`
2. **Expected**: `profile_form.php?error=future_dob`

### 4e. Valid full submission
1. Fill in:
   - Forename: `Alice`
   - Surname: `Brown`
   - DOB: `15/03/1988`
   - Tick skills: Childcare, First Aid
   - Tick qualifications: DBS Check
   - Tick transport: Walking, Public Transport (at least 1 required)
   - Tick availability: Monday 09:00–13:00, Friday 14:00–18:00
2. Click Save Profile
3. **Expected**: Redirected to `home.php` showing Alice's profile

### 4f. Verify DB was populated
```sql
SELECT * FROM volunteer WHERE volunteer_id = 'VOL001';
-- Expected: forename=Alice, surname=Brown, date_of_birth=1988-03-15

SELECT * FROM volunteer_skill WHERE volunteer_id = 'VOL001';
-- Expected: 2 rows (skill_ids for Childcare and First Aid)

SELECT * FROM volunteer_transport WHERE volunteer_id = 'VOL001';
-- Expected: 2 rows (transport_ids for Walking and Public Transport)

SELECT * FROM availability WHERE volunteer_id = 'VOL001';
-- Expected: 2 rows (Monday 09:00-13:00, Friday 14:00-18:00)
```

---

## STEP 5 — Test Home Page (Postcode Masking & Data Display)

1. Log in as VOL002 / BN2 2BB (complete profile)
2. On `home.php` verify:
   - Name shows: `Jane Smith`
   - Postcode shows: `BN2****` (first 3 chars, rest replaced with *)
   - Skills list: Childcare, Counselling, First Aid
   - Qualifications: DBS Check, First Aid Certificate
   - Transport: Public Transport, Walking
   - Availability table: Monday 09:00–13:00, Wednesday 14:00–18:00, Friday 09:00–17:00
   - "Edit Profile" link present (points to process_update.php)
   - "Log Out" link present

### 5a. Home page for incomplete volunteer
1. In DB, set VOL001's forename back to NULL:
   ```sql
   UPDATE volunteer SET volunteer_forename = NULL WHERE volunteer_id = 'VOL001';
   ```
2. Log in as VOL001 → **Expected**: Redirected to `profile_form.php`

---

## STEP 6 — Test Profile Amendment (process_update.php)

1. Log in as VOL002
2. From home.php click "Edit Profile"
3. Verify form is **pre-populated**:
   - Forename field: `Jane`
   - Surname field: `Smith`
   - DOB field: `15/06/1985`
   - Childcare, Counselling, First Aid ticked
   - DBS Check, First Aid Certificate ticked
   - Walking and Public Transport ticked
   - Monday, Wednesday, Friday ticked with correct times
4. Change surname to `Williams`, add skill `Gardening`, tick `Saturday 10:00–14:00`
5. Click Update Profile
6. **Expected**: Redirected to `home.php` showing updated data
7. **Verify in DB**:
   ```sql
   SELECT volunteer_surname FROM volunteer WHERE volunteer_id = 'VOL002';
   -- Expected: Williams

   SELECT COUNT(*) FROM volunteer_skill WHERE volunteer_id = 'VOL002';
   -- Expected: 4 (Childcare, Counselling, First Aid, Gardening)
   ```

---

## STEP 7 — Test Staff Login

1. Visit: `http://localhost/HOMESTART/staff_login.php`
2. Verify CSRF token is in the form source
3. Enter **admin** / **wrongpassword**
4. **Expected**: `staff_login.php?error=invalid`
5. Enter **admin** / **staffpass123**
6. **Expected**: Redirected to `staff_dashboard.php`

### 7a. Session separation test
1. Open a second browser tab
2. Visit `http://localhost/HOMESTART/home.php` → should redirect to `login.php` (no volunteer session)
3. Log in as a volunteer in that tab
4. **Both sessions work independently** — staff session and volunteer session are on different keys (`staff_id` vs `volunteer_id`)

---

## STEP 8 — Test Staff Dashboard

After logging in as staff, on `staff_dashboard.php` verify:

1. **Total volunteers** shows `3` (or however many are in DB with profiles)
2. **Skill coverage table**:
   - Every skill in the `skill` table appears (even if 0 volunteers have it)
   - Skills with fewer than 3 volunteers show a ⚠ GAP warning in red
   - Skills covered by 3+ volunteers show "OK" in green
3. **Availability by day**:
   - Monday should show 2 (VOL001 Alice + VOL002 Jane)
   - Verify the counts match what's in the availability table
4. The **Search Volunteers** form is visible with skill checkboxes, postcode input, day dropdown, time inputs, transport dropdown
5. The **Match Volunteers** form shows a single postcode input

---

## STEP 9 — Test Search Filters (search.php)

From the staff dashboard:

### 9a. No filters (return all volunteers)
1. Submit the search form with nothing selected
2. **Expected**: All volunteers with complete profiles listed

### 9b. Single skill filter
1. Tick "Childcare" only
2. **Expected**: Only VOL002 Jane (has Childcare) — VOL003 Tom does not have Childcare
3. Verify in DB: `SELECT * FROM volunteer_skill vs JOIN skill s ON s.skill_id = vs.skill_id WHERE s.skill_name = 'Childcare';`

### 9c. Multiple skills filter (AND logic)
1. Tick "Childcare" AND "First Aid"
2. **Expected**: Only volunteers who have BOTH skills (Jane has both; Alice after Step 4e also has both)

### 9d. Postcode partial filter
1. Enter `BN2` in the postcode field
2. **Expected**: Only VOL002 (postcode BN2 2BB)

### 9e. Transport mode filter
1. Select "Vehicle" from transport dropdown
2. **Expected**: Only VOL003 Tom (has Vehicle transport)

### 9f. Day availability filter
1. Select "Wednesday" from the day dropdown
2. **Expected**: Only VOL002 Jane (available Wednesdays)

### 9g. Combined filters
1. Tick "Childcare", select transport "Walking"
2. **Expected**: Only volunteers who have both (Jane has Childcare + Walking)

---

## STEP 10 — Test Matching Algorithm (match.php)

### 10a. Without Google Maps API key (fallback test)
1. In `config.php`, leave the key as `YOUR_API_KEY_HERE`
2. From staff dashboard, enter task postcode: `BN1 1AA`
3. Click "Find Best Volunteers"
4. **Expected**:
   - All volunteers are listed and ranked
   - Travel Score column shows 0 for everyone (no API key)
   - Yellow warning banner: "Google Maps API key not configured"
   - Volunteers are differentiated only by skills and availability scores
   - Tom Jones (3 skills, 3 avail slots) should score higher than others with fewer entries

### 10b. With Google Maps API key (live test)
1. Add your real API key to `config.php`
2. Enter task postcode: `BN1 1AA` (or any real UK postcode)
3. **Expected**:
   - Travel time in seconds appears in the Travel (sec) column
   - Volunteers closer to BN1 1AA score higher on Travel Score
   - Ranked list is sorted by TOTAL score (skills + avail + travel) descending

### 10c. Cache verification
1. Run the same match twice
2. **Expected**: Second run is instant (no API delay) — check the `cache/` directory contains `.json` files
3. Each file is named by MD5 hash of `origin_destination_mode`
4. Open one JSON file: should contain `{"duration_seconds": <number>}`

### 10d. Skill refinement
1. On the match results page, expand "Refine matching criteria"
2. Tick "Childcare" as a required skill
3. Click "Re-run Match"
4. **Expected**: Volunteers without Childcare score 0 on skills (0/50)
   Volunteers WITH Childcare score 50/50 on skills

---

## STEP 11 — Verify Audit Log

After completing the tests above, run this SQL in phpMyAdmin:

```sql
SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 30;
```

You should see entries for every significant event. Verify these specific event types exist:

| event_type              | When it should appear                          |
|-------------------------|------------------------------------------------|
| `login_success`         | After successful volunteer login               |
| `login_failed`          | After wrong volunteer credentials              |
| `login_blocked`         | After hitting the 5-attempt throttle           |
| `profile_submit`        | After VOL001's profile was saved               |
| `home_page_visit`       | Every time home.php was loaded                 |
| `profile_updated`       | After using process_update.php to edit profile |
| `staff_login_success`   | After admin logged in                          |
| `staff_login_failed`    | After wrong staff password                     |
| `staff_dashboard_view`  | After staff dashboard was loaded               |
| `staff_search`          | After each search.php query                    |
| `match_search`          | After each match.php run                       |

**Check IP addresses are recorded:**
```sql
SELECT DISTINCT ip_address FROM audit_log;
-- For localhost testing, expected: 127.0.0.1 (or ::1 for IPv6)
```

**Check actor IDs are correct:**
```sql
SELECT actor_volunteer_id, event_type FROM audit_log;
-- Volunteer events: actor_volunteer_id = 'VOL001', 'VOL002' etc.
-- Staff events: actor_volunteer_id = NULL
```

---

## Common Problems & Fixes

| Problem | Likely cause | Fix |
|---------|-------------|-----|
| White screen / blank page | PHP error with display_errors off | Add `ini_set('display_errors', 1);` at top of the file temporarily |
| "Database connection failed" | MySQL not running or wrong credentials | Start MySQL in XAMPP, check dbconnection.php credentials |
| Login redirects to itself | CSRF token not being set | Ensure `session_start()` is at the very top of login.php before any output |
| `create_staff.php` hash mismatch | Old hash in homestart.sql was generated on a different system | Run create_staff.php to generate a fresh hash for your server |
| Google Maps API returns `REQUEST_DENIED` | API key not enabled for Distance Matrix API | In Google Cloud Console: APIs & Services → Enable "Distance Matrix API" |
| match.php travel score = 0 | API key not in config.php | Edit config.php and replace `YOUR_API_KEY_HERE` with your real key |
| Session not persisting | `session_start()` after output | Make sure `session_start()` is the very first line, before any HTML/whitespace |

---

## Google Maps API Key — Step by Step

1. Go to [https://console.cloud.google.com/](https://console.cloud.google.com/)
2. Sign in with a Google account
3. Click **Select a project** → **New Project** → Name it "HomeStart" → **Create**
4. In the left menu: **APIs & Services** → **Library**
5. Search for **"Distance Matrix API"** → click it → click **Enable**
6. Left menu: **APIs & Services** → **Credentials**
7. Click **+ Create Credentials** → **API Key**
8. Copy the key shown
9. (Recommended) Click **Edit API key** → under **API restrictions** select **Restrict key** → choose **Distance Matrix API** → **Save**
10. Paste the key into `config.php`:
    ```php
    define('GOOGLE_MAPS_API_KEY', 'AIzaSy...(your key here)...');
    ```

**Billing note:** You must add a billing account but Google gives $200 free credit per month.
The Distance Matrix API costs ~$5 per 1000 requests. For testing this project you will use under 100 requests total — well within the free tier.
