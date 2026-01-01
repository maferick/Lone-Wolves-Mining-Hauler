# Database scripts

- `001_schema.sql` → schema
- `002_seed.sql` → seed data
- `003_views.sql` → optional views
- `900_drop_all.sql` → **utility** to drop everything (never import during normal setup)

Normal setup:
```bash
php createdb.php --import=./db
```

Danger:
```bash
php createdb.php --import=./db --include-drop=1
```
