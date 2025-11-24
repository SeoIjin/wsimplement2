<?php
session_start();
require_once 'audit_trail_helper.php';

// Database connection
$host = '127.0.0.1';
$dbname = 'users';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get admin info
$admin_id = $_SESSION['user_id'] ?? 0;
$admin_email = $_SESSION['user_email'] ?? 'Unknown';

// Get ticket ID from URL or use first available
$ticket_id = $_GET['ticket_id'] ?? null;

// Handle POST requests (updates/deletes)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'delete_request') {
            $stmt = $pdo->prepare("SELECT requesttype FROM requests WHERE ticket_id = ?");
            $stmt->execute([$_POST['ticket_id']]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("DELETE FROM requests WHERE ticket_id = ?");
            $stmt->execute([$_POST['ticket_id']]);
            
            logRequestDelete($admin_id, $admin_email, $_POST['ticket_id'], $request['requesttype']);
            
            header("Location: admindashboard.php?msg=deleted");
            exit;
            
        } elseif ($_POST['action'] === 'add_update') {
            $stmt = $pdo->prepare("SELECT id, status, priority FROM requests WHERE ticket_id = ?");
            $stmt->execute([$_POST['ticket_id']]);
            $request_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $request_id = $request_data['id'];
            $old_status = $request_data['status'];
            $old_priority = $request_data['priority'];
            
            $stmt = $pdo->prepare("UPDATE requests SET status = ?, priority = ? WHERE ticket_id = ?");
            $stmt->execute([$_POST['new_status'], $_POST['priority'], $_POST['ticket_id']]);
            
            $update_message = $_POST['update_message'] ?? 'Status updated by admin';
            $stmt_insert = $pdo->prepare("INSERT INTO request_updates (request_id, status, message, updated_by) VALUES (?, ?, ?, ?)");
            $stmt_insert->execute([$request_id, $_POST['new_status'], $update_message, 'Admin']);
            
            if ($old_status !== $_POST['new_status']) {
                logRequestUpdate($admin_id, $admin_email, $_POST['ticket_id'], $old_status, $_POST['new_status'], $update_message);
            }
            
            if ($old_priority !== $_POST['priority']) {
                logPriorityChange($admin_id, $admin_email, $_POST['ticket_id'], $old_priority, $_POST['priority']);
            }
            
            $_SESSION['success_message'] = "Request updated successfully!";
            header("Location: ReqDet&Upd.php?ticket_id=" . $_POST['ticket_id']);
            exit;
        }
    }
}

// Fetch request details
if ($ticket_id) {
    $stmt = $pdo->prepare("SELECT r.*, a.email, a.barangay FROM requests r 
                           LEFT JOIN account a ON r.user_id = a.id 
                           WHERE r.ticket_id = ?");
    $stmt->execute([$ticket_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->query("SELECT r.*, a.email, a.barangay FROM requests r 
                         LEFT JOIN account a ON r.user_id = a.id 
                         ORDER BY r.submitted_at DESC LIMIT 1");
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($request) {
        $ticket_id = $request['ticket_id'];
    }
}

if (!$request) {
    die("No request found");
}

// Fetch updates
$stmt = $pdo->prepare("SELECT * FROM request_updates WHERE request_id = ? ORDER BY created_at DESC");
$stmt->execute([$request['id']]);
$updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formatTimestamp($timestamp) {
    return date('M d, Y - g:i A', strtotime($timestamp));
}

function getStatusColor($status) {
    $colors = [
        'PENDING' => '#f59e0b',
        'UNDER REVIEW' => '#f59e0b',
        'IN PROGRESS' => '#ff6b4a',
        'READY' => '#3b82f6',
        'COMPLETED' => '#16a34a'
    ];
    return $colors[strtoupper($status)] ?? '#6b7280';
}

function getPriorityColor($priority) {
    $colors = [
        'LOW' => '#16a34a',
        'MEDIUM' => '#f59e0b',
        'HIGH' => '#ef4444'
    ];
    return $colors[strtoupper($priority)] ?? '#6b7280';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <title>Request Details - <?= htmlspecialchars($ticket_id) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@100;300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: #DAF1DE;
      min-height: 100vh;
    }

    /* Header */
    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      background: white;
      padding: 0.625rem 1.25rem;
      box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .page-header .logo-section {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .page-header img {
      height: 50px;
      border-radius: 50%;
    }

    .page-header .title-section div:first-child {
      font-weight: 500;
      font-size: 1rem;
    }

    .page-header .title-section div:last-child {
      font-size: 0.875rem;
      color: #666;
    }

    .header-actions {
      display: flex;
      gap: 0.5rem;
      align-items: center;
    }

    .header-actions .btn {
      padding: 0.375rem 1rem;
      font-size: 0.875rem;
      cursor: pointer;
      border: none;
      border-radius: 0.375rem;
      background: #228650;
      color: white;
      transition: opacity 0.2s;
      font-family: 'Poppins', sans-serif;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .header-actions .btn:hover {
      opacity: 0.9;
    }

    /* Main Content */
    .main-content {
      padding: 2rem;
      max-width: 1400px;
      margin: 0 auto;
    }

    /* Success Message */
    .success-message {
      background: #d1fae5;
      color: #065f46;
      padding: 1rem;
      border-radius: 0.5rem;
      margin-bottom: 1.5rem;
      border-left: 4px solid #059669;
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 1rem;
      margin-bottom: 2rem;
    }

    .stat-card {
      background: white;
      border-radius: 0.75rem;
      padding: 1.5rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .stat-icon {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      flex-shrink: 0;
    }

    .stat-icon.status {
      background: #fef3c7;
      color: #f59e0b;
    }

    .stat-icon.priority {
      background: #fee2e2;
      color: #ef4444;
    }

    .stat-icon.user {
      background: #dbeafe;
      color: #2563eb;
    }

    .stat-info h3 {
      font-size: 1.125rem;
      font-weight: 600;
      color: #2c3e50;
      margin-bottom: 0.25rem;
    }

    .stat-info p {
      font-size: 0.875rem;
      color: #7f8c8d;
    }

    /* Section */
    .section {
      background: white;
      border-radius: 0.75rem;
      padding: 1.5rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      margin-bottom: 1.5rem;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.25rem;
      padding-bottom: 0.75rem;
      border-bottom: 2px solid #f3f4f6;
    }

    .section-title {
      font-size: 1.25rem;
      font-weight: 600;
      color: #2c3e50;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    /* Details Grid */
    .details-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1rem;
    }

    .detail-card {
      background: #f8f9fa;
      border-radius: 0.5rem;
      padding: 1rem;
      border: 1px solid #e5e7eb;
    }

    .detail-label {
      color: #7f8c8d;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 0.5rem;
    }

    .detail-value {
      color: #2c3e50;
      font-size: 0.9375rem;
      font-weight: 500;
    }

    /* Description Box */
    .description-box {
      background: #f0fdf4;
      border: 1px solid #d1fae5;
      border-radius: 0.5rem;
      padding: 1.25rem;
      margin-top: 1rem;
    }

    .description-text {
      color: #166534;
      font-size: 0.9375rem;
      line-height: 1.7;
      margin-bottom: 0.75rem;
    }

    .description-time {
      color: #7f8c8d;
      font-size: 0.8125rem;
      display: flex;
      align-items: center;
      gap: 0.375rem;
    }

    /* Timeline */
    .timeline {
      position: relative;
      padding-left: 2rem;
    }

    .timeline::before {
      content: '';
      position: absolute;
      left: 0.5rem;
      top: 0;
      bottom: 0;
      width: 2px;
      background: #e5e7eb;
    }

    .timeline-item {
      position: relative;
      padding-bottom: 1.5rem;
    }

    .timeline-item:last-child {
      padding-bottom: 0;
    }

    .timeline-marker {
      position: absolute;
      left: -1.625rem;
      width: 1.25rem;
      height: 1.25rem;
      border-radius: 50%;
      border: 3px solid white;
      box-shadow: 0 0 0 2px;
    }

    .timeline-content {
      background: #f9fafb;
      border-radius: 0.5rem;
      padding: 1rem;
      border-left: 3px solid;
    }

    .timeline-status {
      font-weight: 600;
      font-size: 0.9375rem;
      margin-bottom: 0.375rem;
    }

    .timeline-time {
      color: #7f8c8d;
      font-size: 0.8125rem;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.375rem;
    }

    .timeline-message {
      color: #4b5563;
      font-size: 0.875rem;
      line-height: 1.5;
      margin-bottom: 0.375rem;
    }

    .timeline-by {
      color: #228650;
      font-size: 0.8125rem;
      font-weight: 500;
    }

    /* Form Controls */
    .form-group {
      margin-bottom: 1rem;
    }

    .form-label {
      display: block;
      color: #2c3e50;
      font-weight: 600;
      font-size: 0.875rem;
      margin-bottom: 0.5rem;
    }

    .form-select,
    .form-textarea {
      width: 100%;
      padding: 0.75rem;
      border: 1px solid #e5e7eb;
      border-radius: 0.5rem;
      font-size: 0.875rem;
      background: white;
      font-family: 'Poppins', sans-serif;
      transition: all 0.2s;
    }

    .form-select:focus,
    .form-textarea:focus {
      outline: none;
      border-color: #228650;
      box-shadow: 0 0 0 3px rgba(34, 134, 80, 0.1);
    }

    .form-textarea {
      resize: vertical;
      min-height: 100px;
    }

    /* Buttons */
    .btn-primary,
    .btn-danger {
      width: 100%;
      padding: 0.75rem 1.25rem;
      border-radius: 0.5rem;
      border: none;
      font-weight: 600;
      font-size: 0.875rem;
      cursor: pointer;
      transition: all 0.2s;
      font-family: 'Poppins', sans-serif;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    .btn-primary {
      background: #228650;
      color: white;
    }

    .btn-primary:hover {
      background: #1a6d3f;
    }

    .btn-danger {
      background: #ef4444;
      color: white;
    }

    .btn-danger:hover {
      background: #dc2626;
    }

    .button-group {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      margin-top: 1rem;
    }

    /* Update Panel */
    .update-panel {
      margin-top: 1rem;
      background: #f0fdf4;
      border: 2px solid #d1fae5;
      border-radius: 0.75rem;
      padding: 1.25rem;
      display: none;
      animation: slideDown 0.3s ease;
    }

    .update-panel.show {
      display: block;
    }

    @keyframes slideDown {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Status Badge */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 9999px;
      font-weight: 600;
      font-size: 0.875rem;
    }

    .priority-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.375rem;
      padding: 0.375rem 0.75rem;
      border-radius: 0.375rem;
      font-weight: 600;
      font-size: 0.8125rem;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: #7f8c8d;
    }

    .empty-state i {
      font-size: 3rem;
      opacity: 0.3;
      margin-bottom: 0.5rem;
    }

    /* Responsive */
    @media (max-width: 1024px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }

      .details-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 768px) {
      .main-content {
        padding: 1rem;
      }
    }
  </style>
</head>
<body>
  <!-- Header -->
  <div class="page-header">
    <div class="logo-section">
      <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRTDCuh4kIpAtR-QmjA1kTjE_8-HSd8LSt3Gw&s" alt="seal">
      <div class="title-section">
        <div>Barangay 170</div>
        <div>Request Details & Updates</div>
      </div>
    </div>
    <div class="header-actions">
      <a href="admindashboard.php" class="btn">
        <i class="fas fa-arrow-left"></i>
        Back to Dashboard
      </a>
    </div>
  </div>

  <div class="main-content">
    <?php if (isset($_SESSION['success_message'])): ?>
      <div class="success-message">
        <i class="fas fa-check-circle"></i>
        <?php 
        echo htmlspecialchars($_SESSION['success_message']); 
        unset($_SESSION['success_message']);
        ?>
      </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon status" style="background: <?= getStatusColor($request['status']) ?>20; color: <?= getStatusColor($request['status']) ?>">
          <i class="fas fa-circle-notch"></i>
        </div>
        <div class="stat-info">
          <h3><?= htmlspecialchars($request['status']) ?></h3>
          <p>Current Status</p>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon priority" style="background: <?= getPriorityColor($request['priority']) ?>20; color: <?= getPriorityColor($request['priority']) ?>">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-info">
          <h3><?= htmlspecialchars($request['priority'] ?: 'Not Set') ?></h3>
          <p>Priority Level</p>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon user">
          <i class="fas fa-user"></i>
        </div>
        <div class="stat-info">
          <h3><?= htmlspecialchars($request['ticket_id']) ?></h3>
          <p>Ticket ID</p>
        </div>
      </div>
    </div>

    <!-- Request Details Section -->
    <div class="section">
      <div class="section-header">
        <h2 class="section-title">
          <i class="fas fa-file-alt"></i>
          Request Information
        </h2>
        <div 
          class="status-badge" 
          style="background: <?= getStatusColor($request['status']) ?>; color: white;"
        >
          <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
          <?= htmlspecialchars($request['status']) ?>
        </div>
      </div>

      <div class="details-grid">
        <div class="detail-card">
          <div class="detail-label"><i class="fas fa-file"></i> Request Type</div>
          <div class="detail-value"><?= htmlspecialchars($request['requesttype']) ?></div>
        </div>

        <div class="detail-card">
          <div class="detail-label"><i class="fas fa-user"></i> Full Name</div>
          <div class="detail-value"><?= htmlspecialchars($request['fullname']) ?></div>
        </div>

        <div class="detail-card">
          <div class="detail-label"><i class="fas fa-phone"></i> Contact Number</div>
          <div class="detail-value"><?= htmlspecialchars($request['contact']) ?></div>
        </div>

        <div class="detail-card">
          <div class="detail-label"><i class="fas fa-envelope"></i> Email Address</div>
          <div class="detail-value"><?= htmlspecialchars($request['email'] ?? 'N/A') ?></div>
        </div>

        <div class="detail-card">
          <div class="detail-label"><i class="fas fa-map-marker-alt"></i> Barangay</div>
          <div class="detail-value"><?= htmlspecialchars($request['barangay'] ?? 'N/A') ?></div>
        </div>

        <div class="detail-card">
          <div class="detail-label"><i class="fas fa-id-card"></i> User ID</div>
          <div class="detail-value"><?= htmlspecialchars($request['user_id']) ?></div>
        </div>
      </div>

      <div class="description-box">
        <div class="detail-label" style="margin-bottom: 0.75rem;"><i class="fas fa-align-left"></i> Description</div>
        <p class="description-text"><?= nl2br(htmlspecialchars($request['description'])) ?></p>
        <div class="description-time">
          <i class="fas fa-clock"></i>
          Submitted: <?= formatTimestamp($request['submitted_at']) ?>
        </div>
      </div>
    </div>

    <!-- Update History Section -->
    <div class="section">
      <div class="section-header">
        <h2 class="section-title">
          <i class="fas fa-history"></i>
          Update History
        </h2>
        <?php if (!empty($updates)): ?>
          <span style="background: #dbeafe; color: #2563eb; padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">
            <?= count($updates) ?> Updates
          </span>
        <?php endif; ?>
      </div>

      <?php if (!empty($updates)): ?>
        <div class="timeline">
          <?php foreach ($updates as $update): 
            $statusColor = getStatusColor($update['status']);
          ?>
            <div class="timeline-item">
              <div 
                class="timeline-marker" 
                style="background: <?= $statusColor ?>; box-shadow: 0 0 0 2px <?= $statusColor ?>;"
              ></div>
              <div 
                class="timeline-content" 
                style="border-left-color: <?= $statusColor ?>;"
              >
                <div class="timeline-status" style="color: <?= $statusColor ?>">
                  <?= htmlspecialchars($update['status']) ?>
                </div>
                <p class="timeline-time">
                  <i class="fas fa-clock"></i>
                  <?= formatTimestamp($update['created_at']) ?>
                </p>
                <?php if (!empty($update['message'])): ?>
                  <p class="timeline-message"><?= htmlspecialchars($update['message']) ?></p>
                <?php endif; ?>
                <p class="timeline-by">
                  <i class="fas fa-user-shield"></i>
                  Updated by: <?= htmlspecialchars($update['updated_by']) ?>
                </p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state">
          <i class="fas fa-inbox"></i>
          <p>No updates yet</p>
          <p style="font-size: 0.875rem;">Status changes will appear here</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Actions Section -->
    <div class="section">
      <div class="section-header">
        <h2 class="section-title">
          <i class="fas fa-tasks"></i>
          Request Management
        </h2>
      </div>

      <!-- Priority Selection -->
      <div class="form-group">
        <label class="form-label" for="prioritySelect">
          <i class="fas fa-exclamation-triangle"></i> Priority Level
        </label>
        <select id="prioritySelect" class="form-select">
          <option value="" <?= empty($request['priority']) ? 'selected' : '' ?>>Not Set</option>
          <option value="LOW" <?= $request['priority'] === 'LOW' ? 'selected' : '' ?>>Low Priority</option>
          <option value="MEDIUM" <?= $request['priority'] === 'MEDIUM' ? 'selected' : '' ?>>Medium Priority</option>
          <option value="HIGH" <?= $request['priority'] === 'HIGH' ? 'selected' : '' ?>>High Priority</option>
        </select>
      </div>

      <div class="button-group">
        <button class="btn-primary" onclick="toggleUpdatePanel()">
          <i class="fas fa-edit"></i> Update Request Status
        </button>
        
        <div id="updatePanel" class="update-panel">
          <form method="POST">
            <input type="hidden" name="action" value="add_update">
            <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket_id) ?>">
            <input type="hidden" name="priority" id="hiddenPriority" value="<?= htmlspecialchars($request['priority']) ?>">
            
            <div class="form-group">
              <label class="form-label" for="new_status">
                <i class="fas fa-sync-alt"></i> New Status
              </label>
              <select id="new_status" name="new_status" class="form-select" required>
                <option value="PENDING">Pending</option>
                <option value="IN PROGRESS">In Progress</option>
                <option value="READY">Ready</option>
                <option value="COMPLETED">Completed</option>
              </select>
            </div>
            
            <div class="form-group">
              <label class="form-label" for="update_message">
                <i class="fas fa-comment"></i> Update Message (Optional)
              </label>
              <textarea 
                id="update_message" 
                name="update_message" 
                class="form-textarea" 
                placeholder="Add a note about this status change..."
              ></textarea>
            </div>
            
            <button class="btn-primary" type="submit">
              <i class="fas fa-save"></i> Save Update
            </button>
          </form>
        </div>
        
        <form method="POST" onsubmit="return confirm('Are you sure you want to delete request <?= htmlspecialchars($ticket_id) ?>? This action cannot be undone!');" style="margin: 0;">
          <input type="hidden" name="action" value="delete_request">
          <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($request['ticket_id']) ?>">
          <button type="submit" class="btn-danger">
            <i class="fas fa-trash"></i> Delete Request
          </button>
        </form>
      </div>
    </div>
  </div>

  <script>
    function toggleUpdatePanel() {
      const panel = document.getElementById('updatePanel');
      panel.classList.toggle('show');
    }
    
    document.getElementById('prioritySelect').addEventListener('change', function() {
      document.getElementById('hiddenPriority').value = this.value;
    });
  </script>
</body>
</html>