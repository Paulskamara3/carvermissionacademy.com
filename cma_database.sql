USE cma_school_management;

-- 1. USER ROLES
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO roles (role_name) VALUES
('admin'),
('teacher'),
('student');


-- 2. USER ACCOUNTS
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    account_status ENUM('active', 'inactive', 'suspended')
        DEFAULT 'active',
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;


-- 3. ACADEMIC YEARS
CREATE TABLE academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_name VARCHAR(30) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'completed', 'upcoming')
        DEFAULT 'upcoming'
) ENGINE=InnoDB;


-- 4. ACADEMIC TERMS
CREATE TABLE terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    term_name VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,

    FOREIGN KEY (academic_year_id)
        REFERENCES academic_years(id),

    UNIQUE (academic_year_id, term_name)
) ENGINE=InnoDB;


-- 5. CLASSES
CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL
) ENGINE=InnoDB;


-- 6. SECTIONS
CREATE TABLE sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    section_name VARCHAR(50) NOT NULL,

    FOREIGN KEY (class_id) REFERENCES classes(id),

    UNIQUE (class_id, section_name)
) ENGINE=InnoDB;


-- 7. SUBJECTS
CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(30) NOT NULL UNIQUE,
    subject_name VARCHAR(120) NOT NULL,
    description TEXT NULL
) ENGINE=InnoDB;


-- 8. STUDENTS
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL UNIQUE,
    admission_number VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    gender ENUM('male', 'female', 'other') NOT NULL,
    date_of_birth DATE NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    guardian_name VARCHAR(150) NULL,
    guardian_phone VARCHAR(30) NULL,
    admission_date DATE NOT NULL,
    student_status ENUM('active', 'graduated', 'transferred', 'suspended')
        DEFAULT 'active',

    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL,

    INDEX idx_student_name (last_name, first_name)
) ENGINE=InnoDB;


-- 9. TEACHERS
CREATE TABLE teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL UNIQUE,
    employee_number VARCHAR(50) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    gender ENUM('male', 'female', 'other') NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    qualification VARCHAR(150) NULL,
    employment_date DATE NULL,
    teacher_status ENUM('active', 'inactive')
        DEFAULT 'active',

    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- 10. CLASS SUBJECT ASSIGNMENTS
CREATE TABLE class_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NULL,
    academic_year_id INT NOT NULL,

    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id),
    FOREIGN KEY (academic_year_id)
        REFERENCES academic_years(id),

    UNIQUE (class_id, subject_id, academic_year_id)
) ENGINE=InnoDB;


-- 11. STUDENT ENROLLMENTS
CREATE TABLE enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    section_id INT NULL,
    academic_year_id INT NOT NULL,
    enrollment_date DATE NOT NULL,
    enrollment_status ENUM('active', 'completed', 'withdrawn')
        DEFAULT 'active',

    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (section_id) REFERENCES sections(id),
    FOREIGN KEY (academic_year_id)
        REFERENCES academic_years(id),

    UNIQUE (student_id, academic_year_id)
) ENGINE=InnoDB;


-- 12. ATTENDANCE RECORDS
CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_status ENUM('present', 'absent', 'late', 'excused')
        NOT NULL,
    marked_by INT NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (marked_by) REFERENCES users(id)
        ON DELETE SET NULL,

    UNIQUE (student_id, attendance_date)
) ENGINE=InnoDB;


-- 13. EXAMINATIONS
CREATE TABLE exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_name VARCHAR(150) NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    term_id INT NOT NULL,
    exam_date DATE NOT NULL,
    total_marks DECIMAL(6,2) NOT NULL,
    created_by INT NULL,

    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (term_id) REFERENCES terms(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- 14. EXAM RESULTS
CREATE TABLE results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    marks_obtained DECIMAL(6,2) NOT NULL,
    grade VARCHAR(10) NULL,
    remarks TEXT NULL,

    FOREIGN KEY (exam_id) REFERENCES exams(id),
    FOREIGN KEY (student_id) REFERENCES students(id),

    UNIQUE (exam_id, student_id)
) ENGINE=InnoDB;


-- 15. SCHOOL NOTICES
CREATE TABLE notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    audience ENUM('all', 'students', 'teachers', 'admins')
        DEFAULT 'all',
    published_by INT NULL,
    published_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATE NULL,

    FOREIGN KEY (published_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;


-- 16. AUDIT LOGS
CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(150) NOT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;