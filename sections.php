<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireRole('admin');

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$classId = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);

if (!$classId || $classId < 1) {
    header('Location: index.php?message=not_found');
    exit;
}

$stmt = $pdo->prepare("SELECT id, class_name FROM classes WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $classId]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header('Location: index.php?message=not_found');
    exit;
}

$errors = [];
$editSection = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $sectionId = filter_var($_POST['section_id'] ?? 0, FILTER_VALIDATE_INT);
        $sectionName = trim($_POST['section_name'] ?? '');

        if ($action === 'add' || $action === 'update') {
            if ($sectionName === '') {
                $errors[] = 'Section name is required.';
            } elseif (mb_strlen($sectionName) > 100) {
                $errors[] = 'Section name must not exceed 100 characters.';
            }

            if (!$errors) {
                $sql = "
                    SELECT id FROM sections
                    WHERE class_id = :class_id
                    AND LOWER(section_name) = LOWER(:section_name)
                ";
                $params = [
                    'class_id' => $classId,
                    'section_name' => $sectionName
                ];

                if ($action === 'update') {
                    $sql .= " AND id != :section_id";
                    $params['section_id'] = $sectionId;
                }

                $sql .= " LIMIT 1";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ($stmt->fetch()) {
                    $errors[] = 'This section already exists in this class.';
                }
            }

            if (!$errors) {
                try {
                    if ($action === 'add') {
                        $stmt = $pdo->prepare("
                            INSERT INTO sections (class_id, section_name)
                            VALUES (:class_id, :section_name)
                        ");

                        $stmt->execute([
                            'class_id' => $classId,
                            'section_name' => $sectionName
                        ]);

                        header('Location: sections.php?class_id=' . $classId . '&message=added');
                        exit;
                    }

                    if ($action === 'update') {
                        if (!$sectionId || $sectionId < 1) {
                            $errors[] = 'Invalid section selected.';
                        } else {
                            $stmt = $pdo->prepare("
                                UPDATE sections
                                SET section_name = :section_name
                                WHERE id = :id AND class_id = :class_id
                            ");

                            $stmt->execute([
                                'section_name' => $sectionName,
                                'id' => $sectionId,
                                'class_id' => $classId
                            ]);

                            header('Location: sections.php?class_id=' . $classId . '&message=updated');
                            exit;
                        }
                    }
                } catch (PDOException $ex) {
                    error_log('Section save error: ' . $ex->getMessage());
                    $errors[] = 'Unable to save the section. Please try again.';
                }
            }
        } elseif ($action === 'delete') {
            if (!$sectionId || $sectionId < 1) {
                $errors[] = 'Invalid section selected.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        DELETE FROM sections
                        WHERE id = :id AND class_id = :class_id
                    ");

                    $stmt->execute([
                        'id' => $sectionId,
                        'class_id' => $classId
                    ]);

                    header('Location: sections.php?class_id=' . $classId . '&message=deleted');
                    exit;
                } catch (PDOException $ex) {
                    error_log('Section delete error: ' . $ex->getMessage());
                    $errors[] = 'Unable to delete this section. It may be in use by other records.';
                }
            }
        }
    }
}

// Load section for editing
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId && $editId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, section_name
            FROM sections
            WHERE id = :id AND class_id = :class_id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $editId,
            'class_id' => $classId
        ]);

        $editSection = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Load sections
$stmt = $pdo->prepare("
    SELECT id, section_name
    FROM sections
    WHERE class_id = :class_id
    ORDER BY section_name ASC
");

$stmt->execute(['class_id' => $classId]);
$sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

$message = $_GET['message'] ?? '';
$successMessages = [
    'added' => 'Section added successfully.',
    'updated' => 'Section updated successfully.',
    'deleted' => 'Section deleted successfully.'
];

$csrfToken = generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Sections | CMA School Management System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
        .sections-container {
            max-width: 1100px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .sections-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .sections-header h2 {
            font-weight: 700;
        }

        .sections-card {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }

        .sections-card h4 {
            font-weight: 700;
            margin-bottom: 20px;
        }

        .sections-card .form-label {
            font-weight: 600;
        }

        .sections-card .form-control {
            padding: 11px 13px;
        }

        .empty-sections {
            text-align: center;
            padding: 35px 15px;
            color: #6c757d;
        }

        .empty-sections i {
            display: block;
            font-size: 35px;
            margin-bottom: 12px;
        }

        @media (max-width: 576px) {
            .sections-container {
                padding: 0 12px;
                margin-top: 20px;
            }

            .sections-card {
                padding: 18px;
            }
        }
    </style>
</head>

<body>

<?php
$activePage = 'classes';
require_once __DIR__ . '/../../includes/admin_sidebar.php';
?>

<button class="sidebar-toggle" id="sidebarToggle" type="button">
    <i class="bi bi-list"></i>
</button>

<main class="admin-main">

    <div class="sections-container">

        <div class="sections-header">
            <div>
                <h2>Manage Sections</h2>
                <p class="text-muted mb-0">
                    Manage sections under <?= e($class['class_name']) ?>.
                </p>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a href="view.php?id=<?= (int)$classId ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Class
                </a>

                <a href="index.php" class="btn btn-outline-primary">
                    All Classes
                </a>
            </div>
        </div>

        <?php if (isset($successMessages[$message])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= e($successMessages[$message]) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Add or Edit Section -->
        <div class="sections-card">

            <h4>
                <i class="bi bi-collection text-primary"></i>
                <?= $editSection ? 'Edit Section' : 'Add New Section' ?>
            </h4>

            <form method="POST" action="sections.php?class_id=<?= (int)$classId ?><?= $editSection ? '&edit=' . (int)$editSection['id'] : '' ?>">

                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <input type="hidden" name="action"
                       value="<?= $editSection ? 'update' : 'add' ?>">

                <?php if ($editSection): ?>
                    <input type="hidden" name="section_id"
                           value="<?= (int)$editSection['id'] ?>">
                <?php endif; ?>

                <div class="row align-items-end g-3">

                    <div class="col-md-8">
                        <label for="section_name" class="form-label">
                            Section Name
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="section_name"
                            name="section_name"
                            placeholder="Example: A, B, C or Blue"
                            maxlength="100"
                            value="<?= e($editSection['section_name'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-save"></i>
                            <?= $editSection ? 'Update Section' : 'Add Section' ?>
                        </button>
                    </div>

                </div>

                <?php if ($editSection): ?>
                    <div class="mt-3">
                        <a href="sections.php?class_id=<?= (int)$classId ?>"
                           class="btn btn-sm btn-outline-secondary">
                            Cancel Editing
                        </a>
                    </div>
                <?php endif; ?>

            </form>
        </div>

        <!-- Sections List -->
        <div class="sections-card">

            <h4>
                <i class="bi bi-list-ul text-primary"></i>
                Sections in <?= e($class['class_name']) ?>
                <span class="badge bg-primary"><?= count($sections) ?></span>
            </h4>

            <?php if ($sections): ?>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">

                        <thead class="table-light">
                            <tr>
                                <th style="width: 80px;">#</th>
                                <th>Section Name</th>
                                <th style="width: 180px;">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($sections as $index => $section): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>

                                    <td class="fw-semibold">
                                        <?= e($section['section_name']) ?>
                                    </td>

                                    <td>
                                        <div class="d-flex gap-2">

                                            <a href="sections.php?class_id=<?= (int)$classId ?>&edit=<?= (int)$section['id'] ?>"
                                               class="btn btn-sm btn-outline-primary"
                                               title="Edit">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>

                                            <form method="POST"
                                                  action="sections.php?class_id=<?= (int)$classId ?>"
                                                  onsubmit="return confirm('Are you sure you want to delete this section?');">

                                                <input type="hidden" name="csrf_token"
                                                       value="<?= e($csrfToken) ?>">

                                                <input type="hidden" name="action"
                                                       value="delete">

                                                <input type="hidden" name="section_id"
                                                       value="<?= (int)$section['id'] ?>">

                                                <button type="submit"
                                                        class="btn btn-sm btn-outline-danger"
                                                        title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>

                                            </form>

                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                </div>

            <?php else: ?>

                <div class="empty-sections">
                    <i class="bi bi-collection"></i>
                    <h5>No Sections Yet</h5>
                    <p class="mb-0">
                        Add the first section for this class using the form above.
                    </p>
                </div>

            <?php endif; ?>

        </div>

    </div>

</main>

<script>
    const sidebarToggle = document.getElementById('sidebarToggle');
    const adminSidebar = document.getElementById('adminSidebar');

    if (sidebarToggle && adminSidebar) {
        sidebarToggle.addEventListener('click', function () {
            adminSidebar.classList.toggle('show');
        });
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
