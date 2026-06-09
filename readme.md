# ⭐ Super Star Chore Chart  
### A SaaS-Style WordPress Plugin for Family Chore Management

Super Star Chore Chart is a family‑friendly, server‑synced chore management system built as a WordPress plugin. It helps families organize daily responsibilities, track paid and unpaid tasks, and keep everyone in sync across multiple devices. 

With recent updates, it now functions as a **standalone Progressive Web App (PWA)** with isolated user accounts, meaning your family members never have to see or interact with the WordPress dashboard!

---

## ✨ New in Version 2.4+

### 📱 Progressive Web App (PWA)
- **Installable:** Users can install the chore chart directly to their iOS or Android home screens for a native full-screen app experience.
- **Smart Prompts:** Native Android install prompts and custom iOS Safari instructions.

### 🔐 Isolated App Authentication
- **No WP Accounts Needed:** Uses a custom, secure cookie-based login system. Users don't need WordPress subscriber accounts.
- **Self-Serve Password Resets:** Built-in "Forgot Password" flow sends a secure, time-limited reset link via email.

### 🔗 Magic Link Invites
- **One-Click Joins:** Family admins can generate a secure "Invite Link." 
- **Frictionless:** When clicked, family members are instantly logged in via a guest account and added to the family chart—no passwords required!

### 🌗 Light / Dark Mode
- **System-Aware:** Automatically detects the user's OS preference (Dark or Light mode).
- **Manual Toggle:** A simple toggle switch in the app header allows users to override the theme, saving their preference instantly.

---

## 👨‍👩‍👧 Core Features

### Family System & Live Sync
- Create a **family** with a shared name and password (or use Magic Links). 
- All members see and update the **same chore chart**.  
- Changes sync automatically across all devices via background server polling.

### 🧒 Per‑Kid Chore Tabs
- Add, rename, or remove kids easily.  
- Each kid gets their own chore list with daily checkboxes (Mon–Sun).  
- Real-time per‑task totals and weekly earnings summaries.
- Clean **black‑and‑white Print Mode** for pinning to the fridge.

### 🧹 Chore Categories & Pay
Organized into intuitive groups (Personal Care, Kitchen Crew, Brain Gigs, etc.). Supports:
- **Unpaid Team Duties** - **Paid Gigs** (Choose between Flat Rate or Per-Day pay structures)

### 🛠️ Edit Mode & Archives
- Easily rename tasks, adjust pay rates, or toggle paid/unpaid status.
- Save a **Default Template** to quickly reset the board every Sunday.  
- Automatically archive completed weeks to review past performance and earnings.

---

## 📦 Installation

1. Download the latest plugin ZIP from the **Releases** page.  
2. In your WordPress Admin, go to **Plugins → Add New → Upload Plugin**.  
3. Upload `super-star-chore-chart.zip` and click **Install Now**, then **Activate**.  
4. A **Chore Chart** page is automatically created for you at `/chore-chart/`.  
5. Use the shortcode `[chore_chart]` on any page to render the app.

*(Note: Ensure your WordPress Permalinks are set to "Post name" for Magic Links and Password Resets to route correctly).*

---

## 🧰 How to Use

1. **Create an Account:** Visit the page where your shortcode is located and create an account with your email.
2. **Create a Family:** Create a new family name and secure password.
3. **Invite Members:** Click the **"🔗 Invite Link"** button to copy a magic URL. Text it to your kids or spouse to let them join instantly!
4. **Customize:** Click **"⚙ Edit Settings"** to set up your default tasks.
5. **Track Progress:** Kids check off tasks daily. Earnings calculate automatically.
6. **Archive:** At the end of the week, click **"🗄 Archive & New Week"** to save the history and reset the board!

---

## 🗂️ Folder Structure

```text
super-star-chore-chart/
├── super-star-chore-chart.php  # Main plugin & URL router
├── readme.txt                  # WP repository readme
├── assets/                     
│   ├── app.js                  # Frontend logic (PWA, Theme, App State)
│   ├── app.css                 # UI Styling (CSS Variables for Dark Mode)
│   ├── sw.js                   # Service Worker for PWA installation
│   └── icon.png                # PWA App Icon
├── includes/                   
│   ├── ajax.php                # Backend endpoints (Saves, Syncs, Emails)
│   ├── db.php                  # Database table creation
│   ├── login.php               # Auth handlers
│   └── shortcode.php           # Renders the Login/App UI
└── admin/                      
    └── settings.php            # WP Admin dashboard settings
