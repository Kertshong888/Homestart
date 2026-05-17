# Home-Start Volunteer Portal — Frontend Integration Guide

**For:** LEO.V on assumptions you can't read evrything just look at the task lists and if any questions scroll below to see the logic or just message me 
**Backend owner:** Ayush (PHP/MySQL)
**Last updated:** May 2026

---

## Your Task List (Priority Order)

These are everything the frontend needs to deliver, mapped directly to the system spec and the backend files that already exist:

- [ ] **1. Style `login.php`** — Volunteer login page (form with Volunteer ID + Postcode fields)
- [ ] **2. Style `profile_form.php`** — First-time profile setup (skills, qualifications, transport checkboxes, availability table, personal info)
- [ ] **3. Style `home.php`** — Volunteer read-only dashboard (profile display, masked postcode, skills/transport/availability tables)
- [ ] **4. Style `process_update.php`** — Edit profile form (same layout as profile_form but pre-filled)
- [ ] **5. Style `staff_login.php`** — Staff-only login page (separate portal from volunteers)
- [ ] **6. Style `staff_dashboard.php`** — Staff dashboard (stats cards, skill gap table, availability chart, search form, match form)
- [ ] **7. Style `search.php`** — Volunteer search results table
- [ ] **8. Style `match.php`** — Ranked volunteer matching results table with colour-coded scores
- [ ] **9. Create shared `style.css`** — Linked from all pages above
- [ ] **10. Add Home-Start branding** — Logo, colour scheme, typography across all pages

---

## Overview — How the System Works

```
Volunteer flow:
  login.php → auth.php → home.php
                       → profile_form.php (if profile incomplete) → profile_submit.php → home.php
  home.php → process_update.php (edit profile) → home.php

Staff flow:
  staff_login.php → staff_auth.php → staff_dashboard.php
                                   → search.php (filtered volunteer list)
                                   → match.php (ranked volunteer matching)
```

The backend (PHP) generates all HTML dynamically. Your job is to make each page look polished using CSS. You will be **editing the existing `.php` files** to add CSS classes and link your stylesheet — you are not creating separate `.html` files.

---

## Critical Rules — Do NOT Change These

These are backend requirements. Breaking them will break the application:

| Rule | Why |
|------|-----|
| **Never change `name="..."` attributes on form inputs** | PHP reads exactly these names from `$_POST` |
| **Never remove `<input type="hidden" name="csrf_token">`** | This prevents security attacks — every form has one |
| **Never change `action="..."` or `method="POST"` on forms** | These route the form to the correct PHP handler |
| **Never remove `<?php ... ?>` blocks** | The PHP generates the dynamic content |
| **Never add a second `session_start()`** | Already at the top of each file |
| **Do not create new `.html` files for forms** | All forms live inside `.php` files |

---

## File-by-File Guide

### 1. `login.php` — Volunteer Login

**What the backend does:** Generates a CSRF token, shows errors from the URL (`?error=missing`, `?error=invalid`, `?error=locked`).

**What you need to add:**
- Home-Start logo / header banner
- Styled card/panel for the login form
- Styled error message (already output in a `<p style="color:red;">` — replace inline style with a CSS class)
- Styled input fields and submit button
- Link to volunteer info / charity branding

**Current HTML structure (simplified):**
```html
<h1>Home-Start Volunteer Portal</h1>
<h2>Volunteer Login</h2>
<p style="color:red;">[error message]</p>   ← add CSS class here
<form method="POST" action="auth.php">
    <input type="hidden" name="csrf_token">
    <input type="text"   name="volunteer_id">
    <input type="text"   name="postcode">
    <button type="submit">Log In</button>
</form>
```

**Suggested additions:**
- Replace `style="color:red;"` with `class="error-message"`
- Add `class="form-card"` to the `<form>` element
- Add `class="btn btn-primary"` to the submit button

---

### 2. `profile_form.php` — First-Time Profile Setup

**What the backend does:** Fetches skills, qualifications, and transport options live from the database and renders checkboxes. Generates a 7-row availability table (Monday–Sunday).

**What you need to add:**
- Multi-section form layout (each `<fieldset>` as a styled card or accordion)
- Styled checkbox lists for skills, qualifications, transport
- Styled availability table with toggleable rows
- Progress indicator (optional — this is a one-time form)
- Clear section headings and helper text

**Form sections (these `<fieldset>` tags already exist):**
```
[Personal Information]  → forename, surname, date of birth (DD/MM/YYYY)
[Skills]                → checkboxes, generated from DB
[Qualifications]        → checkboxes, generated from DB
[Transport Modes]       → exactly 4 checkboxes: Walking, Cycling, Vehicle, Public Transport
[Availability]          → table: Day | Available? | From | To  (7 rows)
```

**Input names (do not change):**
```
name="forename"
name="surname"
name="dob"
name="skill_ids[]"
name="qualification_ids[]"
name="transport_ids[]"
name="avail[0][enabled]"  through  name="avail[6][enabled]"
name="avail[0][start]"   through  name="avail[6][start]"
name="avail[0][end]"     through  name="avail[6][end]"
```

---

### 3. `home.php` — Volunteer Dashboard (Read-Only)

**What the backend does:** Fetches the full volunteer profile via database JOINs and displays it. Masks the postcode (e.g. `BN1****`). Logs every visit to the audit log.

**What you need to add:**
- Dashboard layout (sidebar or top navigation)
- Profile info displayed in styled cards/panels, not raw `<table>` tags (or style the tables)
- Skills, qualifications, transport as styled badge/tag elements
- Availability displayed as a styled weekly grid or table
- "Edit Profile" button linking to `process_update.php`
- "Log Out" link in the nav

**Data already rendered by backend:**
```
Volunteer ID, Full Name, Date of Birth (formatted DD/MM/YYYY), Postcode (masked)
List of skills
List of qualifications
List of transport modes
Availability table (Day, From, To)
```

**Postcode masking is handled in PHP** — you will just display whatever is echoed. Example output: `BN1****`

---

### 4. `process_update.php` — Edit Profile

**What the backend does:** On GET (page load), fetches the volunteer's current data and pre-ticks all checkboxes with their existing selections. On POST (form submit), validates and updates the database.

**What you need to add:**
- Same layout and styling as `profile_form.php` (reuse your CSS classes)
- "Back to Dashboard" link (already present as plain `<a>` tag)
- Visual indicator that this is an edit, not a first-time setup

**This file has the same `<fieldset>` structure and identical `name` attributes as `profile_form.php`.** If you style one, the classes work on both.

---

### 5. `staff_login.php` — Staff Login

**What the backend does:** Separate login portal for staff — completely independent of volunteer login. Staff log in with a username and password (not ID + postcode).

**What you need to add:**
- Different visual identity from volunteer login (e.g. "Staff Portal" header, different colour scheme or banner)
- Same form card/panel pattern as `login.php`
- The link to volunteer login is already present: `<a href="login.php">Volunteer Login →</a>`

**Input names (do not change):**
```
name="username"
name="password"
```

---

### 6. `staff_dashboard.php` — Staff Dashboard

**What the backend does:**
- Queries total volunteer count
- Fetches skill coverage with gap detection (skills with < 3 volunteers flagged)
- Fetches availability counts per day of week
- Renders a filter form (posts to `search.php`)
- Renders a task postcode input (posts to `match.php`)

**What you need to add:**
- Stats cards at the top (Total Volunteers, number of skill gaps, etc.)
- Skill coverage as a styled table with red/green indicators (classes `.gap` and `OK` already applied by backend)
- Availability breakdown as a bar chart or styled table (consider Chart.js for visual impact)
- Filter form as a styled sidebar or collapsible panel
- "Find Best Volunteers" input as a prominent call-to-action card

**Existing CSS hooks (already in the PHP):**
```css
.gap   { color: red; font-weight: bold; }  ← already applied to gap skills
```
You can replace the inline style in the `<style>` block with your own `.css` file.

---

### 7. `search.php` — Search Results

**What the backend does:** Receives filter parameters from `staff_dashboard.php`, queries the database, and returns a table of matching volunteers with their skills and transport modes.

**What you need to add:**
- Results count badge (`X volunteer(s) found` — already output as `<p>`)
- Styled results table (ID, Name, Postcode, Skills, Transport)
- "No results" empty state design
- "Back to Dashboard" link (already present)
- Optional: export button (purely cosmetic — no backend needed for the UI element)

---

### 8. `match.php` — Matching Results

**What the backend does:** Scores every volunteer out of 100 (Skills 50pts + Availability 30pts + Travel time 20pts) and returns a ranked table. Colour classes are already applied:

```
.score-high  (green)  → total score 70+
.score-mid   (yellow) → total score 40–69
.score-low   (red)    → total score below 40
```

**What you need to add:**
- Ranked list with rank numbers styled prominently (medal icons for top 3 optional)
- Score breakdown shown visually (e.g. mini progress bars per score component)
- Colour legend styled nicely (already present as text at the bottom)
- "Refine criteria" section styled as a collapsible accordion (`<details>` element already used)
- Warning banner when API key is missing (backend outputs this in a `<div>` with inline style — add a class)

**Column structure of the results table:**
```
Rank | Volunteer Name | Postcode | Skills | Best Transport Mode | Travel (seconds) |
Skills Score | Availability Score | Travel Score | TOTAL SCORE
```

---

## How to Add Your CSS

### Step 1 — Create your stylesheet
Create `/Applications/XAMPP/xamppfiles/htdocs/HOMESTART/css/style.css`

### Step 2 — Link it in every PHP file
Add this line inside the `<head>` tag of each file:
```html
<link rel="stylesheet" href="css/style.css">
```

The `<head>` block already exists in every file — just add the link tag inside it.

### Step 3 — Replace inline styles
The backend uses a few inline styles for quick visibility. Replace these with classes:

| Inline style | Suggested class |
|---|---|
| `style="color:red;"` on error messages | `class="alert alert-error"` |
| `style="color:green;"` on OK status | `class="status-ok"` |
| `style="background-color:#d4edda;"` on high-score rows | `class="score-high"` (already set) |
| `style="background-color:#fff3cd;"` on mid rows | `class="score-mid"` (already set) |
| `style="background-color:#f8d7da;"` on low rows | `class="score-low"` (already set) |

The `<style>` block in `staff_dashboard.php` and `match.php` can be deleted once you have those classes in `style.css`.

---

## Suggested CSS Classes to Define

```css
/* Layout */
.container       /* centred page wrapper */
.card            /* white box with shadow */
.nav-bar         /* top navigation */

/* Forms */
.form-group      /* label + input pair */
.btn             /* base button */
.btn-primary     /* main action button */
.btn-secondary   /* secondary/cancel button */

/* Alerts */
.alert           /* base alert box */
.alert-error     /* red error message */
.alert-success   /* green success message */

/* Tables */
.data-table      /* styled table */

/* Staff dashboard */
.stat-card       /* overview number cards */
.gap             /* skill gap — red bold (already applied by PHP) */
.status-ok       /* skill ok — green */

/* Match results */
.score-high      /* green row (already applied by PHP) */
.score-mid       /* yellow row (already applied by PHP) */
.score-low       /* red row (already applied by PHP) */
```

---

## Where Things Live

```
HOMESTART/
├── css/
│   └── style.css          ← create this
├── img/
│   └── logo.png           ← add Home-Start logo here
├── login.php              ← edit this
├── profile_form.php       ← edit this
├── home.php               ← edit this
├── process_update.php     ← edit this
├── staff_login.php        ← edit this
├── staff_dashboard.php    ← edit this
├── search.php             ← edit this
├── match.php              ← edit this
│
│   (backend-only — do not edit these)
├── auth.php
├── staff_auth.php
├── profile_submit.php
├── dbconnection.php
├── audit.php
├── config.php
└── homestart.sql
```

---

## Test Accounts (for checking your designs work)

| Portal | Login page | Username/ID | Password/Postcode |
|--------|-----------|-------------|-------------------|
| Volunteer (incomplete profile) | `login.php` | `VOL001` | `BN1 1AA` |
| Volunteer (full profile — Jane Smith) | `login.php` | `VOL002` | `BN2 2BB` |
| Volunteer (full profile — Tom Jones) | `login.php` | `VOL003` | `BN3 3CC` |
| Staff | `staff_login.php` | `admin` | `staffpass123` |

---

## Recommended Design Reference

- **Home-Start branding colours:** Green (#4CAF50 or similar) for the charity feel — check Home-Start Arun, Worthing & Adur's actual website for brand colours
- **Font suggestion:** Open Sans or Roboto (both free via Google Fonts)
- **Framework:** You can use Bootstrap 5 or plain CSS — both work. If using Bootstrap, add the CDN link in `<head>` and use Bootstrap classes directly on existing elements

---

## Questions to Sync on With Ayush

Before starting, agree on these:

1. **CSS framework** — plain CSS or Bootstrap 5?
2. **Logo file** — where is it, what format?
3. **Brand colours** — what hex values?
4. **Navigation** — should volunteer and staff portals look visually different?
5. **Mobile responsiveness** — is it required for this submission?
6. **Chart.js** — do you want to add a visual chart for the availability breakdown on staff_dashboard.php?
