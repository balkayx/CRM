<?php
/**
 * Görev Detay Sayfası
 * @version 3.1.0
 * @date 2025-05-30 21:16:11
 * @author anadolubirlik
 * @description Modern Material UI tasarım güncellemesi
 */

// Yetki kontrolü
if (!is_user_logged_in() || !isset($_GET['id'])) {
    return;
}

$task_id = intval($_GET['id']);
global $wpdb;
$tasks_table = $wpdb->prefix . 'insurance_crm_tasks';

// Yönetici kontrolü
$is_admin = current_user_can('administrator') || current_user_can('insurance_manager');

// Temsilci yetkisi kontrolü
$current_user_rep_id = get_current_user_rep_id();
$where_clause = "";
$where_params = array($task_id);

// Temsilcinin rolünü kontrol et
$patron_access = false;
if ($current_user_rep_id) {
    $rep_role = $wpdb->get_var($wpdb->prepare(
        "SELECT role FROM {$wpdb->prefix}insurance_crm_representatives WHERE id = %d",
        $current_user_rep_id
    ));
    
    // Eğer role 1 (Patron) veya 2 (Müdür) ise, tüm verilere erişim sağla
    if ($rep_role == 1 || $rep_role == 2) {
        $patron_access = true;
    }
}

if (!$is_admin && !$patron_access && $current_user_rep_id) {
    $where_clause = " AND t.representative_id = %d";
    $where_params[] = $current_user_rep_id;
}

// Görev bilgilerini al
$task = $wpdb->get_row($wpdb->prepare("
    SELECT t.*,
           c.first_name, c.last_name,
           p.policy_number, p.policy_type, p.insurance_company,
           u.display_name AS rep_name
    FROM $tasks_table t
    LEFT JOIN {$wpdb->prefix}insurance_crm_customers c ON t.customer_id = c.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_policies p ON t.policy_id = p.id
    LEFT JOIN {$wpdb->prefix}insurance_crm_representatives r ON t.representative_id = r.id
    LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
    WHERE t.id = %d
    {$where_clause}
", $where_params));

if (!$task) {
    echo '<div class="notification-banner notification-error">
        <div class="notification-icon">
            <i class="fas fa-exclamation-circle"></i>
        </div>
        <div class="notification-content">
            Görev bulunamadı veya görüntüleme yetkiniz yok.
        </div>
    </div>';
    return;
}

// Task note CRUD operations
if (isset($_POST['action']) && $_POST['action'] === 'save_task_note' && isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'task_note_nonce')) {
    $current_user_id = get_current_user_id();
    $is_wp_admin_or_manager = current_user_can('administrator') || current_user_can('insurance_manager');
    
    if ($_POST['action'] == 'save_task_note' && isset($_POST['task_id']) && isset($_POST['note_content'])) {
        $task_id = intval($_POST['task_id']);
        $note_content = sanitize_textarea_field($_POST['note_content']);
        
        if (!empty($note_content)) {
            $notes_table = $wpdb->prefix . 'insurance_crm_task_notes';
            
            // Check if table exists before attempting insert
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$notes_table'");
            if ($table_exists != $notes_table) {
                $notice_message = 'Notlar tablosu bulunamadı. Lütfen sistem yöneticisi ile iletişime geçin.';
                $notice_type = 'error';
            } else {
                $result = $wpdb->insert(
                    $notes_table,
                    array(
                        'task_id' => $task_id,
                        'note_content' => $note_content,
                        'created_by' => $current_user_id,
                        'created_at' => current_time('mysql')
                    ),
                    array('%d', '%s', '%d', '%s')
                );
                
                if ($result === false) {
                    $notice_message = 'Not kaydedilemedi. Hata: ' . $wpdb->last_error;
                    $notice_type = 'error';
                } else {
                    $notice_message = 'Not başarıyla kaydedildi.';
                    $notice_type = 'success';
                }
            }
        } else {
            $notice_message = 'Not içeriği boş olamaz.';
            $notice_type = 'error';
        }
    }
    
    // Redirect to prevent form resubmission
    if (!empty($notice_message)) {
        $redirect_url = add_query_arg(array(
            'notice_message' => urlencode($notice_message),
            'notice_type' => $notice_type
        ), $_SERVER['REQUEST_URI']);
        wp_redirect($redirect_url);
        exit;
    }
}

// Handle notice display from URL parameters
if (isset($_GET['notice_message'])) {
    $notice_message = urldecode($_GET['notice_message']);
    $notice_type = isset($_GET['notice_type']) ? $_GET['notice_type'] : 'info';
}

// Function to get task notes
if (!function_exists('get_task_notes')) {
    function get_task_notes($task_id) {
    global $wpdb;
    $current_user_id = get_current_user_id();
    $is_wp_admin_or_manager = current_user_can('administrator') || current_user_can('insurance_manager');
    $notes_table = $wpdb->prefix . 'insurance_crm_task_notes';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$notes_table'");
    if ($table_exists != $notes_table) {
        return array(); // Return empty array if table doesn't exist
    }
    
    $notes = $wpdb->get_results($wpdb->prepare("
        SELECT n.*, u.display_name as created_by_name
        FROM $notes_table n
        LEFT JOIN {$wpdb->users} u ON n.created_by = u.ID
        WHERE n.task_id = %d
        ORDER BY n.created_at DESC
    ", $task_id));
    
    // Add permission flags
    foreach ($notes as $note) {
        $note->can_edit = ($note->created_by == $current_user_id || $is_wp_admin_or_manager);
        
        // Format dates
        $note->created_at_formatted = date('d.m.Y H:i', strtotime($note->created_at));
        if ($note->updated_at) {
            $note->updated_at_formatted = date('d.m.Y H:i', strtotime($note->updated_at));
        }
    }
    
    return $notes;
}
}

// Görev son tarih kontrolü
$current_date = date('Y-m-d H:i:s');
$is_overdue = strtotime($task->due_date) < strtotime($current_date) && $task->status !== 'completed' && $task->status !== 'cancelled';

// Görev içeriğini doğru şekilde biçimlendir
$task_description_formatted = nl2br(esc_html($task->task_description));

// Öncelik ve durum bilgilerini oluştur
function get_task_priority_text($priority) {
    switch ($priority) {
        case 'low': return 'Düşük Öncelik';
        case 'medium': return 'Orta Öncelik';
        case 'high': return 'Yüksek Öncelik';
        case 'urgent': return 'Acil Öncelik';
        default: return ucfirst($priority) . ' Öncelik';
    }
}

function get_task_status_text($status) {
    switch ($status) {
        case 'pending': return 'Beklemede';
        case 'in_progress': return 'İşlemde';
        case 'completed': return 'Tamamlandı';
        case 'cancelled': return 'İptal Edildi';
        default: return ucfirst($status);
    }
}

function get_status_color($status) {
    switch ($status) {
        case 'pending': return 'var(--primary)';
        case 'in_progress': return 'var(--warning)';
        case 'completed': return 'var(--success)';
        case 'cancelled': return 'var(--outline)';
        default: return 'var(--outline)';
    }
}

function get_priority_color($priority) {
    switch ($priority) {
        case 'low': return 'var(--success)';
        case 'medium': return 'var(--warning)';
        case 'high': return 'var(--danger)';
        case 'urgent': return 'var(--danger)';
        default: return 'var(--outline)';
    }
}

// Gecikme süresi hesaplama
$delay_text = "";
if ($is_overdue) {
    $diff = strtotime($current_date) - strtotime($task->due_date);
    $days = floor($diff / (60 * 60 * 24));
    $hours = floor(($diff - ($days * 60 * 60 * 24)) / (60 * 60));
    $delay_text = ($days > 0) ? "$days gün $hours saat" : "$hours saat";
}

?>


<div class="task-detail-container">
    <div class="task-header">
        <div class="header-content">
            <div class="breadcrumb">
                <a href="?view=tasks"><i class="fas fa-tasks"></i> Görevler</a>
                <i class="fas fa-angle-right"></i>
                <span>Görev Detayı</span>
            </div>
            
            <h1 class="task-title"><?php echo esc_html($task->task_title); ?></h1>
            
            <div class="task-meta">
                <div class="status-badge status-<?php echo esc_attr($task->status); ?>">
                    <?php echo get_task_status_text($task->status); ?>
                </div>
                
                <div class="status-badge priority-<?php echo esc_attr($task->priority); ?>">
                    <?php echo get_task_priority_text($task->priority); ?>
                </div>
                
                <?php if ($is_overdue): ?>
                <div class="status-badge overdue">
                    <i class="fas fa-exclamation-circle"></i> Gecikmiş
                </div>
                <?php endif; ?>
            </div>
        </div>
            
        <div class="task-actions">
            <a href="?view=tasks" class="btn btn-ghost">
                <i class="fas fa-arrow-left"></i>
                <span>Listeye Dön</span>
            </a>
            
            <a href="?view=tasks&action=edit&id=<?php echo $task_id; ?>" class="btn btn-outline">
                <i class="fas fa-edit"></i>
                <span>Düzenle</span>
            </a>
            
            <button type="button" class="btn btn-info add-note-btn" data-task-id="<?php echo $task_id; ?>">
                <i class="fas fa-sticky-note"></i>
                <span>Not Ekle</span>
            </button>
            
            <?php if ($is_admin || $patron_access || $task->representative_id == $current_user_rep_id): ?>
            <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task_id, 'complete_task_' . $task_id); ?>" class="btn btn-primary"
                onclick="return confirm('Bu görevi tamamlandı olarak işaretlemek istediğinizden emin misiniz?');">
                <i class="fas fa-check"></i>
                <span>Tamamla</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Görev İçeriği -->
    <div class="task-content">
        <!-- Ana Bilgiler Kartı -->
        <div class="card details-card">
            <div class="card-header">
                <h2><i class="fas fa-clipboard-list"></i> Görev Detayları</h2>
            </div>
            <div class="card-body">
                <div class="description-container">
                    <h3>Görev Açıklaması</h3>
                    <div class="description-box">
                        <?php echo $task_description_formatted; ?>
                    </div>
                </div>
                
                <div class="info-grid">
                    <div class="info-group">
                        <h3>Müşteri Bilgileri</h3>
                        <div class="info-item">
                            <div class="info-label">Müşteri:</div>
                            <div class="info-value">
                                <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>" class="link">
                                    <?php echo esc_html($task->first_name . ' ' . $task->last_name); ?>
                                </a>
                            </div>
                        </div>
                        
                        <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
                        <div class="info-item">
                            <div class="info-label">İlgili Poliçe:</div>
                            <div class="info-value">
                                <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>" class="link">
                                    <?php echo esc_html($task->policy_number); ?>
                                </a>
                                <?php if (!empty($task->policy_type)): ?>
                                <span class="policy-type-tag"><?php echo esc_html($task->policy_type); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($task->insurance_company)): ?>
                                <span class="policy-company-tag"><?php echo esc_html($task->insurance_company); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="info-item">
                            <div class="info-label">İlgili Poliçe:</div>
                            <div class="info-value no-value">Poliçe belirtilmemiş</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="info-group">
                        <h3>Görev Bilgileri</h3>
                        <div class="info-item">
                            <div class="info-label">Son Tarih:</div>
                            <div class="info-value <?php echo $is_overdue ? 'danger-text' : ''; ?>">
                                <?php echo date('d.m.Y H:i', strtotime($task->due_date)); ?>
                                <?php if ($is_overdue): ?>
                                <span class="overdue-tag"><?php echo $delay_text; ?> gecikme</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Sorumlu Temsilci:</div>
                            <div class="info-value">
                                <?php echo !empty($task->rep_name) ? esc_html($task->rep_name) : '<span class="no-value">Atanmamış</span>'; ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Oluşturulma:</div>
                            <div class="info-value">
                                <?php echo date('d.m.Y H:i', strtotime($task->created_at)); ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">Son Güncelleme:</div>
                            <div class="info-value">
                                <?php echo date('d.m.Y H:i', strtotime($task->updated_at)); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Durum Kartı -->
        <div class="card status-card status-<?php echo esc_attr($task->status); ?>">
            <div class="card-header">
                <h2><i class="fas fa-chart-line"></i> Durum</h2>
            </div>
            <div class="card-body">
                <div class="status-info">
                    <?php 
                    switch ($task->status) {
                        case 'pending':
                            $status_icon = 'clock';
                            $status_text = 'Bu görev beklemede. Henüz çalışmaya başlanmadı.';
                            break;
                        case 'in_progress':
                            $status_icon = 'spinner fa-spin';
                            $status_text = 'Bu görev üzerinde şu anda çalışılıyor.';
                            break;
                        case 'completed':
                            $status_icon = 'check-circle';
                            $status_text = 'Bu görev tamamlandı.';
                            break;
                        case 'cancelled':
                            $status_icon = 'ban';
                            $status_text = 'Bu görev iptal edildi.';
                            break;
                        default:
                            $status_icon = 'question-circle';
                            $status_text = 'Bu görevin durumu belirsiz.';
                    }
                    ?>
                    
                    <div class="status-icon">
                        <i class="fas fa-<?php echo $status_icon; ?>"></i>
                    </div>
                    
                    <div class="status-details">
                        <h3><?php echo get_task_status_text($task->status); ?></h3>
                        <p><?php echo $status_text; ?></p>
                        
                        <?php if ($task->status === 'completed'): ?>
                            <p class="completion-date">
                                <i class="fas fa-calendar-check"></i> 
                                Tamamlanma: <?php echo date('d.m.Y H:i', strtotime($task->updated_at)); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($is_overdue && $task->status !== 'completed' && $task->status !== 'cancelled'): ?>
                <div class="overdue-alert">
                    <div class="overdue-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="overdue-details">
                        <h3>Görev Gecikmiş</h3>
                        <p>Bu görevin son tarihi <?php echo date('d.m.Y H:i', strtotime($task->due_date)); ?> idi.</p>
                        <p>Gecikme: <?php echo $delay_text; ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($task->status !== 'completed' && $task->status !== 'cancelled'): ?>
                <div class="status-actions">
                    <?php if ($is_admin || $patron_access || $task->representative_id == $current_user_rep_id): ?>
                    <a href="<?php echo wp_nonce_url('?view=tasks&action=complete&id=' . $task_id, 'complete_task_' . $task_id); ?>" class="btn btn-primary btn-lg">
                        <i class="fas fa-check"></i> Görevi Tamamla
                    </a>
                    <?php endif; ?>
                    
                    <a href="?view=tasks&action=edit&id=<?php echo $task_id; ?>" class="btn btn-outline btn-lg">
                        <i class="fas fa-cog"></i> Durumu Değiştir
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- İlişkili Bilgiler -->
    <div class="related-section">
        <h2><i class="fas fa-link"></i> İlişkili İçerikler</h2>
        
        <div class="related-cards">
            <div class="related-card">
                <div class="related-icon customer-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="related-content">
                    <h3>Müşteri</h3>
                    <p><?php echo esc_html($task->first_name . ' ' . $task->last_name); ?></p>
                    <a href="?view=customers&action=view&id=<?php echo $task->customer_id; ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-external-link-alt"></i> Müşteri Detayları
                    </a>
                </div>
            </div>
            
            <?php if (!empty($task->policy_id) && !empty($task->policy_number)): ?>
            <div class="related-card">
                <div class="related-icon policy-icon">
                    <i class="fas fa-file-contract"></i>
                </div>
                <div class="related-content">
                    <h3>İlgili Poliçe</h3>
                    <p><?php echo esc_html($task->policy_number); ?></p>
                    <a href="?view=policies&action=view&id=<?php echo $task->policy_id; ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-external-link-alt"></i> Poliçe Detayları
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="related-card">
                <div class="related-icon task-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="related-content">
                    <h3>Müşteri Görevleri</h3>
                    <p>Bu müşteriye ait diğer görevleri görüntüleyin</p>
                    <a href="?view=tasks&customer_id=<?php echo $task->customer_id; ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-list"></i> Müşterinin Görevleri
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Görev Notları Bölümü -->
    <div class="task-notes-section">
        <div class="section-header">
            <h2><i class="fas fa-sticky-note"></i> Görev Notları</h2>
            <button type="button" class="btn btn-primary" onclick="showAddNoteModal(<?php echo $task_id; ?>)">
                <i class="fas fa-plus"></i> Yeni Not Ekle
            </button>
        </div>
        
        <div id="stickyNotesContainer" class="sticky-notes-container">
            <?php
            // Get task notes using the existing function
            $task_notes = get_task_notes($task_id);
            if (!empty($task_notes)): 
                foreach ($task_notes as $note): ?>
                    <div class="sticky-note" data-note-id="<?php echo $note->id; ?>">
                        <div class="sticky-note-header">
                            <span class="sticky-note-author"><?php echo esc_html($note->created_by_name); ?></span>
                            <span class="sticky-note-date"><?php echo esc_html($note->created_at_formatted); ?></span>
                        </div>
                        <div class="sticky-note-content">
                            <?php echo nl2br(esc_html($note->note_content)); ?>
                        </div>
                    </div>
                <?php endforeach;
            else: ?>
                <div class="no-notes-message">
                    <i class="fas fa-sticky-note"></i>
                    <p>Henüz bu göreve ait not bulunmuyor.</p>
                    <p>İlk notu eklemek için "Yeni Not Ekle" butonunu kullanın.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Task Notes Modals -->
<div id="addNoteModal" class="task-modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-sticky-note"></i> Yeni Not Ekle</h3>
            <button class="modal-close" onclick="closeModal('addNoteModal')">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="post" action="">
            <div class="modal-body">
                <div class="form-group">
                    <label for="newNoteContent">Not İçeriği:</label>
                    <textarea name="note_content" id="newNoteContent" rows="5" placeholder="Not içeriğinizi buraya yazın..." required></textarea>
                </div>
                <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
                <input type="hidden" name="action" value="save_task_note">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('task_note_nonce'); ?>">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addNoteModal')">
                    <i class="fas fa-times"></i> İptal
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Kaydet
                </button>
            </div>
        </form>
    </div>
</div>



<style>
:root {
    /* Colors */
    --primary: #1976d2;
    --primary-dark: #1565c0;
    --primary-light: #42a5f5;
    --secondary: #9c27b0;
    --success: #2e7d32;
    --warning: #f57c00;
    --danger: #d32f2f;
    --info: #0288d1;
    
    /* Neutral Colors */
    --surface: #ffffff;
    --surface-variant: #f5f5f5;
    --surface-container: #fafafa;
    --on-surface: #1c1b1f;
    --on-surface-variant: #49454f;
    --outline: #79747e;
    --outline-variant: #cac4d0;
    
    /* Typography */
    --font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    --font-size-xs: 0.75rem;
    --font-size-sm: 0.875rem;
    --font-size-base: 1rem;
    --font-size-lg: 1.125rem;
    --font-size-xl: 1.25rem;
    --font-size-2xl: 1.5rem;
    
    /* Spacing */
    --spacing-xs: 0.25rem;
    --spacing-sm: 0.5rem;
    --spacing-md: 1rem;
    --spacing-lg: 1.5rem;
    --spacing-xl: 2rem;
    --spacing-2xl: 3rem;
    
    /* Border Radius */
    --radius-sm: 0.25rem;
    --radius-md: 0.5rem;
    --radius-lg: 0.75rem;
    --radius-xl: 1rem;
    
    /* Shadows */
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    
    /* Transitions */
    --transition-fast: 150ms ease;
    --transition-base: 250ms ease;
    --transition-slow: 350ms ease;
}

.task-detail-container {
    max-width: 1200px;
    margin: 0 auto;
    font-family: var(--font-family);
    color: var(--on-surface);
    padding-bottom: var(--spacing-2xl);
}

/* Başlık alanı */
.task-header {
    background-color: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.header-content {
    margin-bottom: var(--spacing-lg);
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
    margin-bottom: var(--spacing-md);
    font-size: var(--font-size-sm);
    color: var(--on-surface-variant);
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: var(--spacing-xs);
}

.breadcrumb a:hover {
    text-decoration: underline;
}

.task-title {
    font-size: var(--font-size-2xl);
    font-weight: 600;
    margin: 0 0 var(--spacing-md) 0;
    color: var(--on-surface);
    line-height: 1.3;
}

.task-meta {
    display: flex;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-xs);
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
}

.status-badge.status-pending {
    background-color: rgba(25, 118, 210, 0.1);
    color: var(--primary);
}

.status-badge.status-in_progress {
    background-color: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.status-badge.status-completed {
    background-color: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.status-badge.status-cancelled {
    background-color: rgba(117, 117, 117, 0.1);
    color: var(--outline);
}

.status-badge.priority-low {
    background-color: rgba(46, 125, 50, 0.1);
    color: var(--success);
}

.status-badge.priority-medium {
    background-color: rgba(245, 124, 0, 0.1);
    color: var(--warning);
}

.status-badge.priority-high,
.status-badge.priority-urgent {
    background-color: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.status-badge.overdue {
    background-color: rgba(211, 47, 47, 0.1);
    color: var(--danger);
}

.task-actions {
    display: flex;
    gap: var(--spacing-md);
    flex-wrap: wrap;
    border-top: 1px solid var(--outline-variant);
    padding-top: var(--spacing-lg);
}

/* İçerik alanı */
.task-content {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
}

.card {
    background-color: var(--surface);
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
}

.card-header {
    background-color: var(--surface-variant);
    padding: var(--spacing-lg) var(--spacing-xl);
    border-bottom: 1px solid var(--outline-variant);
}

.card-header h2 {
    margin: 0;
    font-size: var(--font-size-lg);
    font-weight: 600;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.card-body {
    padding: var(--spacing-xl);
}

/* Durum kartı renkleri */
.status-card.status-pending {
    border-left: 4px solid var(--primary);
}

.status-card.status-in_progress {
    border-left: 4px solid var(--warning);
}

.status-card.status-completed {
    border-left: 4px solid var(--success);
}

.status-card.status-cancelled {
    border-left: 4px solid var(--outline);
}

/* Açıklama alanı */
.description-container {
    margin-bottom: var(--spacing-xl);
}

.description-container h3 {
    font-size: var(--font-size-base);
    font-weight: 600;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface);
}

.description-box {
    background-color: var(--surface-variant);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    line-height: 1.6;
    color: var(--on-surface);
    white-space: pre-line;
    border: 1px solid var(--outline-variant);
}

/* Bilgi grid */
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--spacing-xl);
}

.info-group h3 {
    font-size: var(--font-size-base);
    font-weight: 600;
    margin: 0 0 var(--spacing-md) 0;
    color: var(--on-surface);
    padding-bottom: var(--spacing-xs);
    border-bottom: 1px dashed var(--outline-variant);
}

.info-item {
    display: flex;
    margin-bottom: var(--spacing-md);
    gap: var(--spacing-md);
    align-items: baseline;
}

.info-label {
    font-weight: 500;
    color: var(--on-surface-variant);
    min-width: 120px;
    flex-shrink: 0;
}

.info-value {
    flex: 1;
}

.info-value.danger-text {
    color: var(--danger);
}

.no-value {
    color: var(--outline);
    font-style: italic;
}

.link {
    color: var(--primary);
    text-decoration: none;
}

.link:hover {
    text-decoration: underline;
}

.policy-type-tag,
.policy-company-tag {
    display: inline-block;
    padding: var(--spacing-xs) var(--spacing-sm);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
    background-color: rgba(25, 118, 210, 0.1);
    color: var(--primary);
    margin-left: var(--spacing-sm);
}

.policy-company-tag {
    background-color: rgba(156, 39, 176, 0.1);
    color: var(--secondary);
}

.overdue-tag {
    display: inline-block;
    margin-left: var(--spacing-sm);
    padding: var(--spacing-xs) var(--spacing-sm);
    background-color: rgba(211, 47, 47, 0.1);
    color: var(--danger);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-xs);
}

/* Durum bilgileri */
.status-info {
    display: flex;
    gap: var(--spacing-xl);
    margin-bottom: var(--spacing-xl);
}

.status-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    flex-shrink: 0;
    background-color: var(--surface-variant);
    box-shadow: var(--shadow-sm);
}

.status-pending .status-icon {
    color: var(--primary);
}

.status-in_progress .status-icon {
    color: var(--warning);
}

.status-completed .status-icon {
    color: var(--success);
}

.status-cancelled .status-icon {
    color: var(--outline);
}

.status-details h3 {
    font-size: var(--font-size-lg);
    font-weight: 600;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface);
}

.status-details p {
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface-variant);
    line-height: 1.5;
}

.completion-date {
    background-color: rgba(46, 125, 50, 0.1);
    color: var(--success) !important;
    padding: var(--spacing-sm) var(--spacing-md);
    border-radius: var(--radius-lg);
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-sm);
    font-weight: 500;
    margin-top: var(--spacing-sm) !important;
}

.overdue-alert {
    background-color: rgba(211, 47, 47, 0.05);
    border: 1px solid rgba(211, 47, 47, 0.2);
    border-radius: var(--radius-lg);
    padding: var(--spacing-lg);
    margin-top: var(--spacing-xl);
    display: flex;
    gap: var(--spacing-lg);
    align-items: flex-start;
}

.overdue-icon {
    font-size: 2rem;
    color: var(--danger);
    flex-shrink: 0;
}

.overdue-details h3 {
    font-size: var(--font-size-base);
    font-weight: 600;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--danger);
}

.overdue-details p {
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface-variant);
    line-height: 1.5;
}

.overdue-details p:last-child {
    margin-bottom: 0;
}

.status-actions {
    margin-top: var(--spacing-xl);
    padding-top: var(--spacing-lg);
    border-top: 1px solid var(--outline-variant);
    display: flex;
    gap: var(--spacing-md);
    flex-wrap: wrap;
}

/* İlişkili içerikler */
.related-section {
    margin-top: var(--spacing-xl);
}

.related-section h2 {
    font-size: var(--font-size-xl);
    font-weight: 600;
    margin: 0 0 var(--spacing-lg) 0;
    color: var(--on-surface);
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
}

.related-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: var(--spacing-lg);
}

.related-card {
    background-color: var(--surface);
    border-radius: var(--radius-xl);
    padding: var(--spacing-xl);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--outline-variant);
    display: flex;
    gap: var(--spacing-lg);
    transition: transform var(--transition-base), box-shadow var(--transition-base);
}

.related-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-md);
}

.related-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.customer-icon {
    background: linear-gradient(135deg, #2196F3, #0D47A1);
    color: white;
}

.policy-icon {
    background: linear-gradient(135deg, #9C27B0, #4A148C);
    color: white;
}

.task-icon {
    background: linear-gradient(135deg, #4CAF50, #1B5E20);
    color: white;
}

.related-content {
    flex: 1;
}

.related-content h3 {
    font-size: var(--font-size-base);
    font-weight: 600;
    margin: 0 0 var(--spacing-sm) 0;
    color: var(--on-surface);
}

.related-content p {
    margin: 0 0 var(--spacing-md) 0;
    color: var(--on-surface-variant);
    line-height: 1.5;
    font-size: var(--font-size-sm);
}

/* Butonlar */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-sm);
    padding: var(--spacing-sm) var(--spacing-md);
    border: 1px solid transparent;
    border-radius: var(--radius-lg);
    font-size: var(--font-size-sm);
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    position: relative;
    overflow: hidden;
    background: none;
    white-space: nowrap;
}

.btn:before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    transition: left 0.5s;
}

.btn:hover:before {
    left: 100%;
}

.btn-primary {
    background: var(--primary);
    color: white;
    box-shadow: var(--shadow-sm);
}

.btn-primary:hover {
    background: var(--primary-dark);
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.btn-outline {
    background: transparent;
    color: var(--primary);
    border-color: var(--outline-variant);
}

.btn-outline:hover {
    background: var(--primary);
    color: white;
    border-color: var(--primary);
}

.btn-ghost {
    background: transparent;
    color: var(--on-surface-variant);
}

.btn-ghost:hover {
    background: var(--surface-variant);
    color: var(--on-surface);
}

.btn-lg {
    padding: var(--spacing-md) var(--spacing-xl);
    font-size: var(--font-size-base);
}

.btn-sm {
    padding: 4px 8px;
    font-size: var(--font-size-xs);
}

/* Responsive design */
@media (max-width: 1024px) {
    .task-content {
        grid-template-columns: 1fr;
        gap: var(--spacing-lg);
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .related-cards {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .task-header {
        padding: var(--spacing-lg);
    }
    
    .task-actions {
        flex-direction: column-reverse;
    }
    
    .btn {
        width: 100%;
    }
    
    .status-info {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .related-card {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
}

@media (max-width: 480px) {
    .task-meta {
        flex-direction: column;
    }
    
    .status-badge {
        width: 100%;
        justify-content: center;
    }
}

/* Task Notes Modal Styles */
.task-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.3s ease;
}

.task-modal .modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    width: 90%;
    max-width: 600px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    animation: slideIn 0.3s ease;
}

.task-modal .modal-header {
    padding: 20px;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    border-radius: 12px 12px 0 0;
}

.task-modal .modal-header h3 {
    margin: 0;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-close {
    background: none;
    border: none;
    font-size: 18px;
    cursor: pointer;
    color: #666;
    padding: 5px;
    border-radius: 4px;
}

.modal-close:hover {
    background: rgba(0, 0, 0, 0.1);
    color: #333;
}

.task-modal .modal-body {
    padding: 20px;
    flex: 1;
    overflow-y: auto;
}

.task-modal .modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    background: #f8f9fa;
    border-radius: 0 0 12px 12px;
}

.notes-list {
    margin-bottom: 20px;
}

.note-item {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
}

.note-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    font-size: 12px;
    color: #666;
}

.note-header strong {
    color: #333;
    font-size: 14px;
}

.note-date {
    color: #888;
}

.btn-delete-note {
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    cursor: pointer;
    font-size: 12px;
}

.btn-delete-note:hover {
    background: #c82333;
}

.note-content {
    color: #333;
    line-height: 1.6;
    white-space: pre-line;
    font-size: 14px;
}

.notes-actions {
    border-top: 1px solid #e0e0e0;
    padding-top: 15px;
}

.task-modal .form-group {
    margin-bottom: 15px;
}

.task-modal .form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.task-modal .form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-family: inherit;
    font-size: 14px;
    resize: vertical;
    min-height: 100px;
}

.task-modal .form-group textarea:focus {
    outline: none;
    border-color: #007cba;
    box-shadow: 0 0 0 2px rgba(0, 124, 186, 0.1);
}

.btn-info {
    background: #17a2b8;
    color: white;
    border-color: #17a2b8;
}

.btn-info:hover {
    background: #138496;
    border-color: #117a8b;
}

.btn-secondary {
    background: #6c757d;
    color: white;
    border-color: #6c757d;
}

.btn-secondary:hover {
    background: #5a6268;
    border-color: #545b62;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from { transform: translateY(-50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; transform: scale(1); }
    to { opacity: 0; transform: scale(0.8); }
}

/* Görev Notları Sticky Note Tasarımı */
.task-notes-section {
    margin-top: 30px;
    padding: 20px;
    background: var(--surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
}

.task-notes-section .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--outline-variant);
}

.task-notes-section .section-header h2 {
    margin: 0;
    color: var(--on-surface);
    font-size: var(--font-size-xl);
    font-weight: 600;
}

.task-notes-section .section-header h2 i {
    color: var(--warning);
    margin-right: 10px;
}

.sticky-notes-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.sticky-note {
    background: #FFFFE0;
    border: 1px solid #F0E68C;
    border-radius: 8px;
    padding: 15px;
    position: relative;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15), 0 2px 4px rgba(0, 0, 0, 0.1);
    transform: rotate(-2deg);
    transition: all 0.3s ease;
    min-height: 120px;
    cursor: default;
}

.sticky-note:nth-child(even) {
    transform: rotate(1deg);
    background: #F0FFF0;
    border-color: #98FB98;
}

.sticky-note:nth-child(3n) {
    transform: rotate(-1deg);
    background: #F0F8FF;
    border-color: #87CEEB;
}

.sticky-note:hover {
    transform: rotate(0deg) scale(1.05);
    z-index: 10;
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.25), 0 4px 8px rgba(0, 0, 0, 0.15);
}

.sticky-note::before {
    content: '';
    position: absolute;
    top: -8px;
    left: 50%;
    transform: translateX(-50%);
    width: 30px;
    height: 15px;
    background: rgba(0, 0, 0, 0.1);
    border-radius: 15px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.sticky-note-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px dashed rgba(0, 0, 0, 0.2);
    font-size: 12px;
    color: #666;
}

.sticky-note-author {
    font-weight: 600;
    color: #333;
}

.sticky-note-date {
    color: #888;
    font-size: 11px;
}

.sticky-note-actions {
    position: absolute;
    top: 8px;
    right: 8px;
    display: flex;
    gap: 5px;
}

.sticky-note-content {
    color: #333;
    line-height: 1.4;
    font-size: 13px;
    word-wrap: break-word;
    margin-top: 8px;
}

.no-notes-message {
    grid-column: 1 / -1;
    text-align: center;
    padding: 40px 20px;
    color: var(--on-surface-variant);
    background: var(--surface-variant);
    border-radius: var(--radius-md);
    border: 2px dashed var(--outline-variant);
}

.no-notes-message i {
    font-size: 48px;
    color: var(--warning);
    margin-bottom: 15px;
    display: block;
}

.no-notes-message p {
    margin: 8px 0;
    font-size: var(--font-size-sm);
}

.no-notes-message p:first-of-type {
    font-weight: 600;
    font-size: var(--font-size-base);
    color: var(--on-surface);
}

/* Responsive tasarım */
@media (max-width: 768px) {
    .sticky-notes-container {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .task-notes-section .section-header {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
    
    .sticky-note {
        transform: rotate(0deg);
        min-height: auto;
    }
    
    .sticky-note:hover {
        transform: scale(1.02);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Notification close functionality
    const closeButtons = document.querySelectorAll('.notification-close');
    if (closeButtons) {
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                const notification = this.closest('.notification-banner');
                if (notification) {
                    notification.style.opacity = '0';
                    setTimeout(() => {
                        notification.style.display = 'none';
                    }, 300);
                }
            });
        });
    }
    
    // Auto-hide notifications after 5 seconds
    const notifications = document.querySelectorAll('.notification-banner');
    if (notifications.length > 0) {
        setTimeout(() => {
            notifications.forEach(notification => {
                notification.style.opacity = '0';
                setTimeout(() => {
                    notification.style.display = 'none';
                }, 300);
            });
        }, 5000);
    }
    
    // Add note button event listeners
    document.querySelectorAll('.add-note-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const taskId = this.dataset.taskId;
            showAddNoteModal(taskId);
        });
    });
    
    // Modal close event listeners
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.task-modal');
            modal.style.display = 'none';
        });
    });
    
    // Close modal when clicking outside
    document.querySelectorAll('.task-modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });
    
    // Escape key to close modals
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.task-modal').forEach(modal => {
                modal.style.display = 'none';
            });
        }
    });
});

// Task Notes Functions
function showAddNoteModal(taskId) {
    const modal = document.getElementById('addNoteModal');
    const textarea = document.getElementById('newNoteContent');
    
    textarea.value = '';
    modal.style.display = 'flex';
    textarea.focus();
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}
</script>