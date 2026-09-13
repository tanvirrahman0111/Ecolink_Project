## Academic Information

**Department:** Department of Computer Science & Engineering  
**University:** University of Information Technology and Sciences  
**Course:** CSE 416 – Software Project Design and Development  
**Semester:** Autumn 2026
**Section:** 7B1

## Team Member

- Md. Tanvir Rahman
- Id:0432320005101116
- Faisal Ahmed
- Id:0432320005101129
- Md. Sayed
- Id:0432320005101148

## Instructor
- Dr. Mahfida Amjad Dipa
- Assistant Professor, Department of CSE


# EcoLink – Smart Surplus Resource Optimization & Redistribution Platform

> A web-based platform for intelligent redistribution of surplus resources through category-specific priority matching, end-to-end donation lifecycle tracking, and measurable social and environmental impact.

## Project Overview

EcoLink is designed to address the problem of usable surplus resources being wasted because donors and receivers are not efficiently connected. The platform brings multiple resource categories into one centralized system and replaces simple manual post-and-claim processes with an intelligent donor–receiver matching mechanism.

The supported resource categories are:

- Food
- Clothes
- Books
- Electronics
- Furniture

The system is designed around three main roles:

- **Donor** — uploads surplus resources and tracks donations.
- **Receiver** — maintains resource needs, accepts matched donations, and manages pickup status.
- **Admin** — verifies users, manages resources/categories, monitors activity, and reviews reports and analytics.

## Core Objectives

1. Unify multiple surplus-resource categories on one platform.
2. Implement a category-specific weighted priority matching algorithm.
3. Automate donor–receiver pairing through an intelligent recommendation engine.
4. Track the complete donation lifecycle.
5. Measure social and environmental impact.
6. Generate waste analytics and recommendations.
7. Provide verification and moderation through an admin system.
8. Improve trust, transparency, and accountability.

## Donation Lifecycle

```text
Available → Matched → Accepted → Pickup → Delivered → Completed
```

The lifecycle allows both donors and receivers to see the progress of a donation from resource upload through final completion.

## Main Features

### Donor Features

- Account creation and login
- Upload surplus resources
- Select resource category
- Enter quantity and condition
- Track donation status
- View donation history

### Receiver Features

- Accept or claim matched donations
- View and update pickup status
- Maintain a demand profile for better matching

### Admin Features

- Verify donors and receivers
- Manage users and resource categories
- View reports and waste analytics
- Monitor the impact dashboard

### Advanced Platform Features

- Priority-based matching algorithm
- End-to-end donation lifecycle tracking
- Impact dashboard
- Waste analytics
- Notification and reminder system
- Admin verification and moderation

## Matching Algorithm

The core innovation of EcoLink is a **category-specific weighted Priority Matching Algorithm**. The proposal describes matching as an intelligent replacement for manual donor–receiver pairing. Matching can consider relevant category-specific factors such as resource information, receiver need, distance, expiry where applicable, demand, and receiver capacity.

The exact weighting and scoring implementation should follow the final system design and database structure.

## Impact Dashboard

EcoLink is intended to make the impact of redistribution measurable. The proposal identifies key impact measures including:

- Beneficiaries reached
- Resources reused
- CO2 emissions saved
- Waste-related analytics

## Technology Stack

| Layer | Technology |
|---|---|
| Frontend | HTML, CSS, JavaScript |
| Backend | PHP |
| Database | MySQL |
| Image / Cloud Storage | Firebase / cloud storage |
| Server | XAMPP / Apache |
| Development Tools | VS Code, phpMyAdmin |

## System Scope

### In Scope

- Web-based donor and receiver platform
- Five resource categories
- Priority-based donor–receiver matching
- Full donation lifecycle tracking
- Impact dashboard and waste analytics
- Admin verification and moderation
- Notification and reminder system

### Out of Scope for the First Release

- Physical logistics and last-mile delivery by the platform
- Online payment gateway integration
- Native mobile application
- Additional language support beyond the stated first-release scope

Physical pickup/delivery remains the responsibility of the donor, receiver, or partner NGO rather than a built-in logistics operation.

## System Modules

```text
EcoLink
│
├── Authentication
│   ├── Donor Login / Registration
│   ├── Receiver Login / Registration
│   └── Admin Access
│
├── Donor Module
│   ├── Dashboard
│   ├── Add Resource
│   ├── Smart Matching
│   ├── Donation Tracking
│   └── Donation History
│
├── Receiver Module
│   ├── Dashboard
│   ├── Demand / Request Management
│   ├── Matched Resources
│   ├── Pickup Status
│   └── Request History
│
├── Matching Engine
│   └── Category-Specific Priority Matching
│
├── Lifecycle Tracking
│   └── Available → Matched → Accepted → Pickup → Delivered → Completed
│
├── Impact & Analytics
│   ├── Impact Dashboard
│   └── Waste Analytics
│
├── Notifications
│   └── Alerts and Reminders
│
└── Admin Panel
    ├── User Verification
    ├── Resource / Category Management
    ├── Moderation
    ├── Reports
    └── Monitoring
```

## Development Plan

The proposal organizes development into four major phases:

### Phase 1 — System Design

- Requirement analysis
- Database design and ER diagram

### Phase 2 — Core Development

- User authentication system
- Donor, receiver, and admin dashboards
- Resource category management
- Priority matching algorithm

### Phase 3 — Advanced Features

- Donation lifecycle tracking
- Notification system
- Impact dashboard and waste analytics
- Admin panel and verification

### Phase 4 — Testing & Deployment

- System testing and bug fixing
- Final deployment and documentation

The proposal defines a **12-week development timeline** for these activities.

## Installation / Local Development

The proposal specifies a local development environment based on XAMPP, Apache, PHP, MySQL, VS Code, and phpMyAdmin.

A typical local setup is:

1. Install XAMPP.
2. Start Apache and MySQL.
3. Place the EcoLink project inside the XAMPP web directory.
4. Create the project database using phpMyAdmin.
5. Configure the PHP database connection.
6. Configure Firebase/cloud storage for resource images if used.
7. Run the project through the local Apache server.

## Proposed Database Areas

The final ER diagram should support the main entities required by the proposal, such as:

- Users
- Donors
- Receivers
- Admins
- Resource Categories
- Resources
- Receiver Requests / Demand Profiles
- Matches
- Donations
- Donation Status History
- Notifications
- Impact / Analytics records

The exact table names, keys, relationships, and fields should follow the team's finalized ER diagram rather than being assumed from this README.

## Project Strengths

According to the proposal, EcoLink's main strengths are:

- Multi-category resource redistribution
- Smart category-specific priority matching
- End-to-end donation lifecycle tracking
- Measurable social and environmental impact

## Known Constraints

The proposal identifies several important limitations and risks:

- The platform requires internet access and active participation.
- Matching quality depends on accurate category-specific data.
- Initial algorithm setup and tuning can be complex.
- Fake donations or claims may create data-quality risks.
- Adoption depends on having enough donors and receivers.

## Future Scope

The proposal identifies the following future opportunities:

- Mobile application
- AI-based demand forecasting
- Route optimization for pickups
- Integration with local NGOs
- Integration with municipal waste-management bodies for city-wide redistribution

## Conclusion

EcoLink extends surplus-resource redistribution beyond food into a unified platform covering food, clothes, books, electronics, and furniture. Its central contribution is a category-specific weighted Priority Matching Algorithm that aims to replace manual post-and-claim processes with intelligent donor–receiver pairing. Combined with lifecycle tracking and impact analytics, the system is intended to make redistribution more measurable, efficient, transparent, and useful.
