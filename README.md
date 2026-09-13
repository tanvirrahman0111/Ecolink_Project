# EcoLink – Smart Surplus Resource Optimization & Redistribution Platform

**Course:** CSE 416 – Software Project Design and Development | Autumn 2026 | Section 7B1
**University:** UITS, Department of Computer Science & Engineering
**Instructor:** Dr. Mahfida Amjad Dipa

**Team:**
- Md. Tanvir Rahman — 0432320005101116
- Faisal Ahmed — 0432320005101129
- Md. Sayed — 0432320005101148

**Repository:** `<paste your GitHub repo link here>`

---

## Overview
EcoLink is a web-based platform that unifies five surplus-resource categories — Food, Clothes, Books, Electronics, Furniture — on one system, and replaces manual "post-and-claim" donation listings with an automated, **priority-based, category-weighted matching algorithm** between donors and verified receivers.

## Tech Stack
HTML, CSS, JavaScript (frontend) · PHP + PDO (backend) · MySQL (database) · XAMPP/Apache (server)

## Feature Coverage (Implemented in this Scaffold)

| Module | Status |
|---|---|
| Database schema (users, donations, demand profiles, matches, lifecycle log, notifications, impact log) | ✅ Done |
| Donor: register, login, upload resource, track donations, advance lifecycle | ✅ Done |
| Receiver: register, demand profile per category, view matches, accept/decline/confirm | ✅ Done |
| **Priority Matching Algorithm** — scores receivers on Urgency, Distance, Demand-fit, Capacity with category-specific weights | ✅ Done |
| Donation lifecycle tracking (Available → Matched → Accepted → Pickup → Delivered → Completed) | ✅ Done |
| Admin: verify users, set capacity scores, tune matching weights, run matching engine | ✅ Done |
| Impact dashboard (CO₂ saved, resources reused, beneficiaries reached, lifecycle funnel) | ✅ Done |
| Notification system | ✅ Done |
| Payment gateway, native mobile app | ⏳ Out of scope (per proposal) |

## Matching Algorithm (Core Contribution)
```
total_score = w_urgency·urgency + w_distance·distance + w_demand·demand + w_capacity·capacity
```
Each sub-score is normalized to 0–100; weights are stored per category in the database and are admin-tunable.

## Local Setup
1. Place project in XAMPP `htdocs`, start Apache + MySQL
2. Import `sql/schema.sql` via phpMyAdmin
3. Visit `setup_admin.php` once to create an admin, then delete that file
4. Register a donor and a receiver to test the matching flow
