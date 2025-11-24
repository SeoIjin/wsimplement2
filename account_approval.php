<?php
session_start();
require_once 'audit_trail_helper.php';

// Require admin session
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: sign-in.php');
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "users";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$admin_id = $_SESSION['user_id'];
$admin_email = $_SESSION['user_email'] ?? 'Unknown';

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    if ($user_id > 0) {
        if ($action === 'approve') {
            // Approve account
            $stmt = $conn->prepare("UPDATE account SET account_status = 'approved', updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                // Get user email for audit log
                $stmt2 = $conn->prepare("SELECT email FROM account WHERE id = ?");
                $stmt2->bind_param("i", $user_id);
                $stmt2->execute();
                $result = $stmt2->get_result();
                $user = $result->fetch_assoc();
                
                // Log the approval
                logAuditTrail(
                    $admin_id,
                    $admin_email,
                    'ACCOUNT_APPROVAL',
                    "Approved account for user: {$user['email']}",
                    'account',
                    $user_id,
                    'pending',
                    'approved'
                );
                
                $stmt2->close();
                $_SESSION['success_message'] = "Account approved successfully!";
            }
            $stmt->close();
            
        } elseif ($action === 'reject') {
            // Get user info before deletion
            $stmt = $conn->prepare("SELECT email, first_name, last_name FROM account WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            // Delete/reject account
            $stmt = $conn->prepare("DELETE FROM account WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                // Log the rejection
                logAuditTrail(
                    $admin_id,
                    $admin_email,
                    'ACCOUNT_REJECTION',
                    "Rejected account registration for: {$user['email']} ({$user['first_name']} {$user['last_name']})",
                    'account',
                    $user_id,
                    'pending',
                    'rejected'
                );
                
                $_SESSION['success_message'] = "Account rejected and removed successfully!";
            }
            $stmt->close();
        }
    }
    
    header("Location: account_approval.php");
    exit();
}

// Fetch pending accounts
$pending_query = "SELECT id, first_name, middle_name, last_name, email, barangay, id_type, resident_type, file_path, created_at 
                  FROM account 
                  WHERE (account_status = 'pending' OR account_status IS NULL) 
                  AND (usertype = '' OR usertype IS NULL)
                  ORDER BY created_at DESC";
$pending_result = $conn->query($pending_query);

// Fetch approved accounts
$approved_query = "SELECT id, first_name, middle_name, last_name, email, barangay, id_type, resident_type, created_at, updated_at 
                   FROM account 
                   WHERE account_status = 'approved' 
                   AND (usertype = '' OR usertype IS NULL)
                   ORDER BY updated_at DESC 
                   LIMIT 20";
$approved_result = $conn->query($approved_query);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <title>Account Approval - Barangay 170</title>
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

    .stat-icon.pending {
      background: #fef3c7;
      color: #f59e0b;
    }

    .stat-icon.approved {
      background: #d1fae5;
      color: #16a34a;
    }

    .stat-icon.total {
      background: #dbeafe;
      color: #2563eb;
    }

    .stat-info h3 {
      font-size: 1.875rem;
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

    .badge-count {
      background: #fef3c7;
      color: #f59e0b;
      padding: 0.25rem 0.625rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 600;
    }

    /* Accounts Grid */
    .accounts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 1rem;
    }

    .account-card {
      background: #f8f9fa;
      border-radius: 0.75rem;
      padding: 1.25rem;
      border: 2px solid #e5e7eb;
      transition: all 0.3s;
    }

    .account-card:hover {
      border-color: #228650;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .account-header {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 1rem;
    }

    .account-avatar {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.25rem;
      font-weight: 600;
      flex-shrink: 0;
    }

    .account-info h3 {
      font-size: 1rem;
      font-weight: 600;
      color: #2c3e50;
      margin-bottom: 0.25rem;
    }

    .account-info p {
      font-size: 0.8125rem;
      color: #7f8c8d;
    }

    .account-details {
      margin-bottom: 1rem;
    }

    .detail-row {
      display: flex;
      justify-content: space-between;
      padding: 0.5rem 0;
      border-bottom: 1px solid #e5e7eb;
      font-size: 0.875rem;
    }

    .detail-row:last-child {
      border-bottom: none;
    }

    .detail-label {
      color: #7f8c8d;
      font-weight: 500;
    }

    .detail-value {
      color: #2c3e50;
      font-weight: 500;
      text-align: right;
    }

    .detail-value.capitalize {
      text-transform: capitalize;
    }

    /* ID Preview */
    .id-preview {
      margin-bottom: 1rem;
      text-align: center;
    }

    .id-preview-btn {
      background: #e5e7eb;
      color: #2c3e50;
      padding: 0.5rem 1rem;
      border-radius: 0.375rem;
      border: none;
      cursor: pointer;
      font-size: 0.875rem;
      font-weight: 500;
      transition: all 0.2s;
      font-family: 'Poppins', sans-serif;
    }

    .id-preview-btn:hover {
      background: #d1d5db;
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 0.5rem;
    }

    .btn-approve,
    .btn-reject {
      flex: 1;
      padding: 0.625rem;
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
      gap: 0.375rem;
    }

    .btn-approve {
      background: #16a34a;
      color: white;
    }

    .btn-approve:hover {
      background: #15803d;
    }

    .btn-reject {
      background: #ef4444;
      color: white;
    }

    .btn-reject:hover {
      background: #dc2626;
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

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.5);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }

    .modal.show {
      display: flex;
    }

    .modal-content {
      background: white;
      border-radius: 0.75rem;
      max-width: 600px;
      width: 100%;
      max-height: 80vh;
      overflow-y: auto;
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }

    .modal-header {
      padding: 1.5rem;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-title {
      font-size: 1.25rem;
      font-weight: 600;
      color: #2c3e50;
    }

    .modal-close {
      background: transparent;
      border: none;
      font-size: 1.5rem;
      color: #7f8c8d;
      cursor: pointer;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.375rem;
      transition: all 0.2s;
    }

    .modal-close:hover {
      background: #f3f4f6;
      color: #2c3e50;
    }

    .modal-body {
      padding: 1.5rem;
    }

    .modal-image {
      width: 100%;
      border-radius: 0.5rem;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    /* Responsive */
    @media (max-width: 1024px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }

      .accounts-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 768px) {
      .main-content {
        padding: 1rem;
      }

      .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
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
        <div>Account Approval System</div>
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
        <div class="stat-icon pending">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-info">
          <h3><?php echo $pending_result->num_rows; ?></h3>
          <p>Pending Approval</p>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon approved">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-info">
          <h3><?php echo $approved_result->num_rows; ?></h3>
          <p>Recently Approved</p>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon total">
          <i class="fas fa-users"></i>
        </div>
        <div class="stat-info">
          <h3><?php echo $pending_result->num_rows + $approved_result->num_rows; ?></h3>
          <p>Total Accounts</p>
        </div>
      </div>
    </div>

    <!-- Pending Accounts Section -->
    <div class="section">
      <div class="section-header">
        <h2 class="section-title">
          <i class="fas fa-clock"></i>
          Pending Accounts
          <?php if ($pending_result->num_rows > 0): ?>
            <span class="badge-count"><?php echo $pending_result->num_rows; ?></span>
          <?php endif; ?>
        </h2>
      </div>

      <div class="accounts-grid">
        <?php if ($pending_result->num_rows > 0): ?>
          <?php while ($account = $pending_result->fetch_assoc()): 
            $initials = strtoupper(substr($account['first_name'], 0, 1) . substr($account['last_name'], 0, 1));
          ?>
            <div class="account-card">
              <div class="account-header">
                <div class="account-avatar"><?php echo $initials; ?></div>
                <div class="account-info">
                  <h3><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></h3>
                  <p><?php echo htmlspecialchars($account['email']); ?></p>
                </div>
              </div>

              <div class="account-details">
                <div class="detail-row">
                  <span class="detail-label">Barangay</span>
                  <span class="detail-value"><?php echo htmlspecialchars($account['barangay']); ?></span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">ID Type</span>
                  <span class="detail-value capitalize">
                    <?php echo htmlspecialchars(str_replace('-', ' ', $account['id_type'])); ?>
                  </span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Resident Type</span>
                  <span class="detail-value capitalize"><?php echo htmlspecialchars($account['resident_type']); ?></span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Registered</span>
                  <span class="detail-value">
                    <?php echo date('M j, Y g:i A', strtotime($account['created_at'])); ?>
                  </span>
                </div>
              </div>

              <?php if (!empty($account['file_path']) && $account['file_path'] !== 'uploads/default.jpg'): ?>
                <div class="id-preview">
                  <button 
                    class="id-preview-btn" 
                    onclick="showIDModal('<?php echo htmlspecialchars($account['file_path']); ?>', '<?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?>')"
                  >
                    <i class="fas fa-eye"></i> View ID Document
                  </button>
                </div>
              <?php endif; ?>

              <div class="action-buttons">
                <form method="POST" style="flex: 1; margin: 0;" onsubmit="return confirm('Are you sure you want to approve this account?');">
                  <input type="hidden" name="user_id" value="<?php echo $account['id']; ?>">
                  <input type="hidden" name="action" value="approve">
                  <button type="submit" class="btn-approve">
                    <i class="fas fa-check"></i>
                    Approve
                  </button>
                </form>
                <form method="POST" style="flex: 1; margin: 0;" onsubmit="return confirm('Are you sure you want to reject and delete this account? This action cannot be undone.');">
                  <input type="hidden" name="user_id" value="<?php echo $account['id']; ?>">
                  <input type="hidden" name="action" value="reject">
                  <button type="submit" class="btn-reject">
                    <i class="fas fa-times"></i>
                    Reject
                  </button>
                </form>
              </div>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="empty-state" style="grid-column: 1 / -1;">
            <i class="fas fa-check-circle"></i>
            <p>No pending accounts</p>
            <p style="font-size: 0.875rem;">All account registrations have been processed</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recently Approved Section -->
    <div class="section">
      <div class="section-header">
        <h2 class="section-title">
          <i class="fas fa-check-circle"></i>
          Recently Approved Accounts
        </h2>
      </div>

      <div class="accounts-grid">
        <?php if ($approved_result->num_rows > 0): ?>
          <?php while ($account = $approved_result->fetch_assoc()): 
            $initials = strtoupper(substr($account['first_name'], 0, 1) . substr($account['last_name'], 0, 1));
          ?>
            <div class="account-card" style="border-color: #16a34a; opacity: 0.8;">
              <div class="account-header">
                <div class="account-avatar" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                  <?php echo $initials; ?>
                </div>
                <div class="account-info">
                  <h3><?php echo htmlspecialchars($account['first_name'] . ' ' . $account['last_name']); ?></h3>
                  <p><?php echo htmlspecialchars($account['email']); ?></p>
                </div>
              </div>

              <div class="account-details">
                <div class="detail-row">
                  <span class="detail-label">Barangay</span>
                  <span class="detail-value"><?php echo htmlspecialchars($account['barangay']); ?></span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Approved</span>
                  <span class="detail-value">
                    <?php echo date('M j, Y g:i A', strtotime($account['updated_at'])); ?>
                  </span>
                </div>
              </div>

              <div style="text-align: center; color: #16a34a; font-weight: 600; font-size: 0.875rem;">
                <i class="fas fa-check-circle"></i> Approved
              </div>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="empty-state" style="grid-column: 1 / -1;">
            <i class="fas fa-users"></i>
            <p>No approved accounts yet</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ID Preview Modal -->
  <div id="idModal" class="modal" onclick="closeIDModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h3 class="modal-title" id="modalTitle">ID Document</h3>
        <button class="modal-close" onclick="closeIDModal()">×</button>
      </div>
      <div class="modal-body">
        <img id="modalImage" class="modal-image" src="" alt="ID Document">
      </div>
    </div>
  </div>

  <script>
    function showIDModal(imagePath, userName) {
      document.getElementById('modalTitle').textContent = userName + ' - ID Document';
      document.getElementById('modalImage').src = imagePath;
      document.getElementById('idModal').classList.add('show');
      document.body.style.overflow = 'hidden';
    }

    function closeIDModal() {
      document.getElementById('idModal').classList.remove('show');
      document.body.style.overflow = 'auto';
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeIDModal();
      }
    });
  </script>
</body>
</html>