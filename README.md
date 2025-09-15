# 📝 BLOG-PHP-MYSQL

A lightweight **PHP + MySQL** blogging CMS with an **Admin Panel** for managing posts and a **User Interface** for reading blogs and leaving feedback.  
Built for simplicity, learning, and quick deployment.

---

## ✨ Features
- 🔑 **Admin Panel**
  - Create, edit, and delete blog posts  
  - Manage uploaded images and content  

- 🌍 **Public Blog**
  - Browse blog listings  
  - Read individual posts  
  - Leave comments / feedback  

- 📂 **Other Goodies**
  - Database dump (`blog_db.sql`) for quick setup  
  - File upload support for blog images  
  - Simple, clean structure with minimal dependencies  

---

## 📂 Project Structure
```
.
├── admin/           # Admin dashboard (login, post management)
├── user/            # Public-facing blog pages
├── assets/          # CSS, JS, images
├── uploads/         # Uploaded files/images
├── vendor/          # Composer dependencies (if any)
├── blog_db.sql      # SQL dump (schema + sample data)
├── db_conn.php      # Database connection helper
├── index.php        # Homepage entry
└── README.md        # Project docs
```

---

## 🛠️ Requirements
- PHP 7.2+  
- MySQL or MariaDB  
- Web server (Apache/Nginx) or PHP built-in server  
- Composer (optional, for vendor packages)  

---

## 🚀 Installation & Setup

1. **Clone the repo**
   ```bash
   git clone https://github.com/Divyam-Varshney/BLOG-PHP-MYSQL.git
   cd BLOG-PHP-MYSQL
   ```

2. **Create the database & import SQL**
   ```bash
   mysql -u root -p -e "CREATE DATABASE blog_db"
   mysql -u root -p blog_db < blog_db.sql
   ```

3. **Configure DB connection**
   - Open `db_conn.php`
   - Set your DB host, username, password, and database name  

4. **Make uploads writable**
   ```bash
   chmod 775 uploads
   ```

5. **Run the project**
   - With PHP built-in server:
     ```bash
     php -S localhost:8000
     ```
   - Open [http://localhost:8000](http://localhost:8000) in your browser  

---

## 🔐 Admin Access
- Navigate to `/admin/` in your browser.  
- Use the admin credentials from `blog_db.sql` (or create one directly in the DB).  

---

## ⚡ Security Notes
- Always change default credentials before deploying publicly.  
- Ensure input validation and sanitization for all forms.  
- Restrict file uploads to safe formats.  
- Use HTTPS for production.  

---

## 🤝 Contributing
Contributions are welcome!  
1. Fork the repo  
2. Create a branch: `git checkout -b feature/your-feature`  
3. Commit changes and push  
4. Open a Pull Request 🚀  

---

## 📜 License
This project is licensed under the **MIT License** – free to use, modify, and distribute.  
See the [LICENSE](./LICENSE) file for details.  

---

## 🌟 Support
If you like this project, consider giving it a ⭐ on GitHub — it helps others discover it!  

---
