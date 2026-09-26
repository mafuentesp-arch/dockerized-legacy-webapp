# Dockerized Legacy Web Application Migration

## From Windows/XAMPP to Docker on Ubuntu Linux

This project demonstrates the migration of an existing PHP/MariaDB web application from a traditional Windows/XAMPP environment to a containerized architecture running on Ubuntu Linux using Docker and Docker Compose.

The objective was not simply to run an application inside a container. The project demonstrates the complete migration process, including application containerization, database isolation, networking, persistent storage, Linux deployment, troubleshooting, and database backup and recovery.

---

# 1. Project Overview

The original application, **ESOL2 Student Management Demo**, was running locally on Windows using:

- XAMPP
- Apache
- PHP
- MariaDB
- Localhost configuration

Before migration, the application data was sanitized and replaced with demonstration data so the project could be used safely as a technical portfolio project.

The application was then containerized and migrated to an Ubuntu Linux server.

### Original Environment

```text
Windows
   |
   +-- XAMPP
       |
       +-- Apache / PHP
       |
       +-- MariaDB
       |
       +-- ESOL2 Application
```

### Target Environment

```text
Client Browser
      |
      | HTTP :8081
      v
Ubuntu Linux Server
      |
      v
Docker Engine
      |
      +-----------------------------+
      |       Docker Compose        |
      |                             |
      |   +---------------------+   |
      |   |     esol2-web       |   |
      |   |    PHP / Apache     |   |
      |   +----------+----------+   |
      |              |              |
      |        Docker Network       |
      |              |              |
      |   +----------v----------+   |
      |   |      esol2-db       |   |
      |   |    MariaDB 10.11    |   |
      |   +----------+----------+   |
      |              |              |
      +--------------|--------------+
                     |
                     v
              Docker Volume
                     |
                     v
             Persistent Data
```

---

# 2. Project Objectives

The main objectives of this laboratory were:

1. Containerize an existing PHP application.
2. Separate the web application and database into independent services.
3. Deploy the environment using Docker Compose.
4. Configure communication between containers using Docker networking.
5. Remove database configuration dependencies on localhost.
6. Use environment variables for database configuration.
7. Implement persistent database storage using Docker volumes.
8. Migrate the application from Windows to Ubuntu Linux.
9. Validate application availability after migration.
10. Validate database persistence after container recreation.
11. Implement and test MariaDB backup and recovery.
12. Document troubleshooting encountered during the migration.
13. Prepare the project for safe publication on GitHub.

---

# 3. Technologies Used

| Technology | Purpose |
|---|---|
| Windows | Original development environment |
| XAMPP | Original PHP/MariaDB environment |
| Ubuntu 26.04 LTS | Target Linux server |
| Docker Engine | Container runtime |
| Docker Compose | Multi-container orchestration |
| PHP 8 | Application runtime |
| Apache | Web server |
| MariaDB 10.11 | Database server |
| Docker Volumes | Persistent database storage |
| Docker Networks | Communication between services |
| SCP / SSH | Windows-to-Linux project transfer |
| PowerShell | Windows administration |
| Linux Shell | Ubuntu administration |

---

# 4. Project Structure

The containerized application uses the following structure:

```text
esol2/
|
|-- .env
|-- .env.example
|-- .gitignore
|-- docker-compose.yml
|-- README.md
|
|-- index.php
|-- login.php
|-- dashboard.php
|-- db.php
|-- ...
|
|-- assets/
|   `-- img/
|
|-- database/
|   `-- esol2_demo.sql
|
|-- docker/
|   `-- apache/
|       `-- Dockerfile
|
|-- includes/
|
`-- backups/
```

The `.env` file contains local configuration and must not be committed to GitHub.

The `.env.example` file provides a safe template for other users.

---

# 5. Creating the PHP/Apache Image

A custom Docker image was created for the web application.

File:

```text
docker/apache/Dockerfile
```

Dockerfile:

```dockerfile
FROM php:8.0-apache

# Install PHP extensions required for MariaDB/MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copy application into Apache DocumentRoot
COPY . /var/www/html/

EXPOSE 80
```

This image provides:

- Apache
- PHP 8
- PDO
- MySQL/MariaDB connectivity
- Application source code

---

# 6. Database Configuration

A traditional XAMPP application commonly connects to:

```text
localhost
```

Inside Docker this approach cannot be used for communication between independent containers.

The application was modified to use environment variables.

Example `db.php`:

```php
<?php

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'esol2';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';

try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    die("Database connection failed: " . $e->getMessage());
}
?>
```

This allows the same application code to run in both:

```text
XAMPP
```

and:

```text
Docker
```

without maintaining two versions of the application.

---

# 7. Environment Variables

Sensitive configuration is stored locally in `.env`.

Example:

```env
MARIADB_DATABASE=esol2
MARIADB_USER=esol2_user
MARIADB_PASSWORD=your_database_password
MARIADB_ROOT_PASSWORD=your_root_password
```

The real `.env` file is excluded from Git.

A safe `.env.example` is included in the repository.

---

# 8. Docker Compose Configuration

Docker Compose manages both application services.

```yaml
services:

  web:
    build:
      context: .
      dockerfile: docker/apache/Dockerfile
    container_name: esol2-web
    ports:
      - "8081:80"
    depends_on:
      - db
    environment:
      DB_HOST: db
      DB_NAME: ${MARIADB_DATABASE}
      DB_USER: ${MARIADB_USER}
      DB_PASSWORD: ${MARIADB_PASSWORD}

  db:
    image: mariadb:10.11
    container_name: esol2-db
    restart: unless-stopped
    environment:
      MARIADB_DATABASE: ${MARIADB_DATABASE}
      MARIADB_USER: ${MARIADB_USER}
      MARIADB_PASSWORD: ${MARIADB_PASSWORD}
      MARIADB_ROOT_PASSWORD: ${MARIADB_ROOT_PASSWORD}
    volumes:
      - esol2_db_data:/var/lib/mysql
      - ./database/esol2_demo.sql:/docker-entrypoint-initdb.d/01-esol2.sql:ro

volumes:
  esol2_db_data:
```

---

# 9. Understanding the Docker Architecture

Two independent containers are created.

### Web Container

```text
esol2-web
```

Runs:

```text
PHP 8 + Apache
```

### Database Container

```text
esol2-db
```

Runs:

```text
MariaDB 10.11
```

Docker Compose automatically creates a private network.

The web application connects to:

```text
DB_HOST=db
```

instead of:

```text
localhost
```

The name `db` corresponds to the database service defined in `docker-compose.yml`.

---

# 10. Database Initialization

A sanitized demonstration database is included:

```text
database/esol2_demo.sql
```

It is mounted into:

```text
/docker-entrypoint-initdb.d/
```

MariaDB automatically executes initialization scripts located in this directory when a new database volume is created.

This makes the environment reproducible.

A developer can clone the project and initialize the demonstration database automatically.

---

# 11. Running the Application on Windows

Before migrating to Linux, the Docker configuration was validated on Windows.

Validate the Compose configuration:

```powershell
docker compose config --quiet
```

Build and start the containers:

```powershell
docker compose up -d --build
```

Check container status:

```powershell
docker compose ps
```

Expected architecture:

```text
esol2-web     PHP/Apache
esol2-db      MariaDB
```

The application was accessed using:

```text
http://localhost:8081/
```

This validated the Dockerized application before migrating it to Linux.

---

# 12. Installing Docker on Ubuntu

The target server used:

```text
Ubuntu 26.04 LTS
x86_64
```

After configuring Docker's official repository, Docker Engine and the Compose plugin were installed.

Installation was validated with:

```bash
docker --version
docker compose version
```

The Docker daemon was verified using:

```bash
systemctl status docker
```

---

# 13. Validating Docker

A simple container was executed:

```bash
docker run --rm hello-world
```

Successful output included:

```text
Hello from Docker!
```

This validated:

```text
Docker Client
      |
      v
Docker Daemon
      |
      v
Docker Hub
      |
      v
Image Download
      |
      v
Container Creation
      |
      v
Container Execution
```

---

# 14. Linux User Configuration

The Linux user was added to the Docker group:

```bash
usermod -aG docker <username>
```

After starting a new login session, Docker commands could be executed without `sudo`.

Example:

```bash
docker ps
```

This avoids running normal Docker administration commands directly as root.

---

# 15. Migrating the Application to Linux

A project directory was created on Ubuntu:

```bash
mkdir -p ~/projects
```

The application was transferred from Windows using SCP:

```powershell
scp -r .\esol2 username@SERVER_IP:/home/username/projects/
```

The transferred files were verified on Linux:

```bash
cd ~/projects/esol2
ls -la
```

---

# 16. Deploying ESOL2 on Ubuntu

Before deployment, the Compose configuration was validated:

```bash
docker compose config
```

The containers were then built and started:

```bash
docker compose up -d --build
```

Container status was verified:

```bash
docker compose ps
```

Both services successfully reached the running state:

```text
esol2-web     Up
esol2-db      Up
```

The application became accessible from another computer using:

```text
http://SERVER_IP:8081/
```

This confirmed successful migration from Windows to Linux.

---

# 17. Persistent Storage

Containers should be considered replaceable.

Database data must therefore exist outside the lifecycle of the database container.

The project uses:

```yaml
volumes:
  - esol2_db_data:/var/lib/mysql
```

Docker creates a named volume similar to:

```text
esol2_esol2_db_data
```

Volumes can be listed using:

```bash
docker volume ls
```

and inspected using:

```bash
docker volume inspect esol2_esol2_db_data
```

---

# 18. Persistence Test

The containers were intentionally removed:

```bash
docker compose down
```

The Docker volume remained available.

The services were then recreated:

```bash
docker compose up -d
```

The application was opened again and authentication succeeded.

Database records remained available.

This demonstrated that:

```text
Container lifecycle != Data lifecycle
```

The containers could be destroyed and recreated without losing the MariaDB database.

> Do not use `docker compose down -v` during this test because `-v` removes the named volume.

---

# 19. Database Backup

A MariaDB logical backup was created from the running database container.

```bash
docker exec esol2-db mariadb-dump \
  -u root \
  -pYOUR_ROOT_PASSWORD \
  --databases esol2 \
  --result-file=/tmp/esol2_backup_linux.sql
```

The backup was copied from the container to the Linux host:

```bash
docker cp \
  esol2-db:/tmp/esol2_backup_linux.sql \
  ./backups/esol2_backup_linux.sql
```

The resulting backup was approximately:

```text
2.7 MB
```

Its existence was verified using:

```bash
ls -lh ./backups/esol2_backup_linux.sql
```

---

# 20. Controlled Recovery Test

Creating a backup is not enough.

A backup should be tested to demonstrate that it can actually restore data.

First, an existing demonstration record was verified.

```sql
SELECT user_id, username, role
FROM users
WHERE user_id = 1;
```

Initial value:

```text
admin01
```

A controlled modification was performed:

```sql
UPDATE users
SET username = 'admin_changed'
WHERE user_id = 1;
```

The modification was confirmed:

```text
admin_changed
```

---

# 21. Database Restore

The backup was copied back into the database container:

```bash
docker cp \
  ./backups/esol2_backup_linux.sql \
  esol2-db:/tmp/esol2_restore.sql
```

The database was restored:

```bash
docker exec esol2-db sh -c \
  'mariadb -u root -pYOUR_ROOT_PASSWORD < /tmp/esol2_restore.sql'
```

The record was checked again:

```sql
SELECT user_id, username, role
FROM users
WHERE user_id = 1;
```

Result:

```text
admin01
```

The controlled modification disappeared and the original backed-up value was recovered.

Therefore the test demonstrated:

```text
BACKUP
   |
   v
Original Data
admin01
   |
   v
Controlled Change
admin_changed
   |
   v
RESTORE
   |
   v
Recovered Data
admin01
```

The backup and recovery procedure was successfully validated.

---

# 22. Troubleshooting and Lessons Learned

Real infrastructure migrations rarely work perfectly on the first attempt.

Several issues were encountered during this project.

## Docker Engine Not Running

During the initial Windows test, Docker commands failed because Docker Desktop's Linux engine was not running.

### Lesson

Always validate the Docker engine before troubleshooting the application:

```bash
docker version
```

---

## Port Conflict

Port `8080` was already being used by another application.

The web service was changed from:

```text
8080:80
```

to:

```text
8081:80
```

### Lesson

Host ports must be checked before exposing container services.

---

## SQL Dump Encoding Problem

An SQL dump initially created using PowerShell redirection contained unexpected NUL characters.

Instead of:

```powershell
mysqldump ... > database.sql
```

the dump was generated directly by `mysqldump`:

```powershell
mysqldump.exe \
  --default-character-set=utf8mb4 \
  --result-file="database\esol2_demo.sql" \
  esol2
```

### Lesson

Database tools should preferably generate their own output files when shell redirection can alter encoding.

---

## Linux Docker Permissions

The Linux user initially received:

```text
permission denied while trying to connect to the Docker API
```

The account was added to the Docker group and a new group/login session was started.

### Lesson

Docker socket access depends on Linux user/group permissions.

---

## Container vs. Volume

After:

```bash
docker compose down
```

the database container no longer existed.

However, the Docker volume remained.

### Lesson

Containers and persistent volumes have different lifecycles.

This distinction is fundamental when designing stateful Docker applications.

---

# 23. Security Considerations

Secrets should never be committed to source control.

The project uses:

```text
.env
```

for local credentials.

The `.gitignore` file excludes:

```text
.env
backups/
*.log
*.sqlite
*.sqlite3
```

A safe configuration template is provided as:

```text
.env.example
```

Real production credentials, private information, database backups, and authentication secrets should never be stored in a public repository.

---

# 24. Verification Checklist

The following tests were completed successfully:

- [x] Existing PHP/MariaDB application runs locally.
- [x] Application container image builds successfully.
- [x] MariaDB container starts successfully.
- [x] Web and database containers communicate.
- [x] Application login works inside Docker.
- [x] Database initialization works.
- [x] Docker Compose configuration validates.
- [x] Docker Engine installed on Ubuntu.
- [x] `hello-world` container executed successfully.
- [x] Project transferred from Windows to Linux.
- [x] Application deployed successfully on Ubuntu.
- [x] Application accessible remotely.
- [x] Docker volume created.
- [x] Containers destroyed and recreated.
- [x] Database persisted after container recreation.
- [x] MariaDB backup created.
- [x] Controlled database modification performed.
- [x] Database restored successfully.
- [x] Original data recovered after restore.

---

# 25. Skills Demonstrated

This project demonstrates practical experience with:

**Containerization**

- Docker Engine
- Docker images
- Dockerfiles
- Docker Compose
- Container lifecycle management

**Linux Administration**

- Ubuntu server administration
- Linux permissions
- Docker service management
- Shell commands
- SSH/SCP

**Database Administration**

- MariaDB
- Database initialization
- Persistent storage
- Logical backups
- Database restoration
- Recovery validation

**Networking**

- Docker internal networking
- Service discovery
- Port mapping
- Remote application access

**Application Migration**

- Legacy application analysis
- Windows-to-Linux migration
- Environment-independent configuration
- Infrastructure troubleshooting

**Security**

- Environment variables
- Secret separation
- `.gitignore`
- Sanitized demonstration data

---

# 26. Results

The original application was successfully transformed from:

```text
Windows
   +
XAMPP
   +
Local Apache
   +
Local MariaDB
```

into:

```text
Ubuntu Linux
      |
Docker Engine
      |
Docker Compose
      |
+-----------------------+
|                       |
PHP/Apache           MariaDB
Container            Container
|                       |
+------ Network --------+
                        |
                        v
                 Persistent Volume
```

The application remained operational after migration, container recreation, and database recovery testing.

---

# 27. Conclusion

This project demonstrated the end-to-end migration of a legacy PHP/MariaDB application from a Windows/XAMPP environment to a containerized Linux infrastructure.

Rather than placing the entire application stack into a single container, the web application and database were separated into independent services. Docker Compose was used to define and reproduce the infrastructure, while Docker networking provided service-to-service communication.

Persistent storage was validated by destroying and recreating the containers without losing application data.

A database backup and recovery procedure was also tested using a controlled data modification. Restoring the backup successfully recovered the original database state.

The final architecture is more portable and reproducible than the original environment and demonstrates practical skills in Docker, Linux administration, database management, application migration, persistent storage, troubleshooting, and disaster-recovery fundamentals.

---

# 28. Evidence / Screenshots

Suggested evidence structure:

```text
docs/
`-- screenshots/
    |-- 01-windows-containers-running.png
    |-- 02-windows-application.png
    |-- 03-docker-linux-installed.png
    |-- 04-linux-containers-running.png
    |-- 05-linux-application.png
    |-- 06-persistent-volume.png
    |-- 07-database-backup.png
    |-- 08-controlled-data-change.png
    `-- 09-database-restored.png
```

Example Markdown:

```markdown
## Windows Docker Environment

![Windows Docker](docs/screenshots/01-windows-containers-running.png)

## Application Before Migration

![Windows Application](docs/screenshots/02-windows-application.png)

## Application Running on Ubuntu

![Ubuntu Application](docs/screenshots/05-linux-application.png)

## Database Recovery Test

![Database Restore](docs/screenshots/09-database-restored.png)
```

---

# 29. Portfolio Summary

**Dockerized Legacy Web Application Migration to Linux**

Containerized and migrated an existing PHP/MariaDB web application from Windows/XAMPP to Ubuntu Linux using Docker and Docker Compose. Separated web and database services, configured Docker networking and environment-based database connectivity, implemented persistent storage, and validated application availability after container recreation. Performed and verified MariaDB backup and recovery through a controlled data modification and restoration test.

---

## Author

**Miguel Fuentes**

Database Administration | Software Development | Cloud & Infrastructure