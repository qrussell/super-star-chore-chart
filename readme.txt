# Super Star Chore Chart  
### A WordPress Plugin for Family Chore Management

Super Star Chore Chart is a family‑friendly, server‑synced chore management system built as a WordPress plugin. It helps families organize daily responsibilities, track paid and unpaid tasks, and keep everyone in sync across multiple devices — all inside your WordPress site.

---

## ✨ Features

### 👨‍👩‍👧 Family System
- Each WordPress user can create a **family** with a shared name and password  
- Other family members join using the family credentials  
- All members see and update the **same chore chart**  
- Changes sync automatically via server‑side polling (default: 15 seconds)

### 🧒 Per‑Kid Chore Tabs
- Add, rename, or remove kids  
- Each kid gets their own chore list  
- Daily checkboxes (Mon–Sun)  
- Per‑task totals and weekly earnings summary  
- Clean **black‑and‑white Print Mode** for easy home printing

### 🧹 Chore Categories
Organized into intuitive groups:
- Personal Care & Gear  
- Shared Spaces & Meals  
- Kitchen Crew  
- Bathroom Patrol  
- General Helpers  
- Brain Gigs  
- Community  

Supports:
- **Unpaid Team Duties**  
- **Paid Gigs** with customizable pay amounts  

### 🛠️ Edit Mode
- Rename tasks  
- Adjust pay rates  
- Toggle paid/unpaid  
- Reorder or remove tasks  

### 📋 Templates & Weekly Archives
- Save a **Default Template** to quickly reset each week  
- Automatically archive completed weeks  
- Review past performance and earnings  

### 🔒 Secure & Reliable
- Server‑side storage in custom WordPress database tables  
- All AJAX requests protected with WordPress nonces  
- Family passwords hashed using `wp_hash_password()`  
- Works with any WordPress theme  

---

## 📦 Installation

1. Download the plugin ZIP from the **Releases** page.  
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.  
3. Upload `super-star-chore-chart.zip` and click **Install Now**.  
4. Activate the plugin.  
5. A **Chore Chart** page is automatically created at:  

/chore-chart/

6. Visit **Settings → Chore Chart** to configure options.  
7. Use the shortcode anywhere:  

[chore_chart]


---

## 🧰 How to Use

### 1. Create or Join a Family
- Logged‑in users create a family name + password  
- Other users join using the same credentials  

### 2. Add Kids
- Each kid gets a tab  
- Customize names and order  

### 3. Customize Tasks
- Add new tasks  
- Mark tasks as paid or unpaid  
- Set pay amounts  
- Organize tasks by category  

### 4. Track Progress
- Kids check off tasks daily  
- Paid tasks automatically calculate totals  
- Weekly earnings summary updates in real time  

### 5. Print or Archive
- Use **Print Mode** for a clean, black‑and‑white printable chart  
- Weekly Archives store past weeks for review  

---

## 🗂️ Folder Structure


super-star-chore-chart/ │ ├── super-star-chore-chart.php ├── readme.txt ├── assets/ ├── includes/ └── templates/


---

## 🧪 Development

Clone the repository:

```bash
git clone https://github.com/qrussell/super-star-chore-chart.git

Install dependencies (if applicable):

composer install
npm install

Build assets:

npm run build

🤝 Contributing

Pull requests are welcome.To contribute:

Fork the repository

Create a feature branch

Commit your changes

Open a pull request

📝 License

GPLv2 or laterSee LICENSE for full details.

❤️ Credits

Created by Quentin to help families build responsibility, independence, and teamwork through a fun, structured chore system.

