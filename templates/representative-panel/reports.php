<?php
/**
 * Next Generation Reports Module - Modern Analytics Dashboard
 * @version 10.0.0 - Complete Rewrite with Advanced Analytics
 * @created 2025-06-29
 * @author Anadolu Birlik CRM Team
 * @description Modern reporting system with deep analytics, advanced filtering, and visual dashboards
 */

// Security check
if (!defined('ABSPATH') || !is_user_logged_in()) {
    wp_die(__('Bu sayfaya erişim yetkiniz bulunmamaktadır.', 'insurance-crm'), __('Erişim Engellendi', 'insurance-crm'), array('response' => 403));
}

// License check - Frontend inline warning
if (!insurance_crm_check_frontend_module_access('reports', 'Raporlar')) {
    // License warning shown, don't load module
    return;
}

// Global variables
global $wpdb;
$policies_table = $wpdb->prefix . 'insurance_crm_policies';
$customers_table = $wpdb->prefix . 'insurance_crm_customers';
$representatives_table = $wpdb->prefix . 'insurance_crm_representatives';
$users_table = $wpdb->users;

/**
 * Advanced Reports Manager Class - Next Generation
 */
class NextGenReportsManager {
    private $wpdb;
    private $user_id;
    private $user_rep_id;
    private $user_role_level;
    private $tables;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->user_id = get_current_user_id();
        
        $this->tables = [
            'policies' => $wpdb->prefix . 'insurance_crm_policies',
            'customers' => $wpdb->prefix . 'insurance_crm_customers',
            'representatives' => $wpdb->prefix . 'insurance_crm_representatives',
            'tasks' => $wpdb->prefix . 'insurance_crm_tasks',
            'offers' => $wpdb->prefix . 'insurance_crm_offers',
            'users' => $wpdb->users
        ];
        
        $this->user_rep_id = $this->getCurrentUserRepId();
        $this->user_role_level = $this->getUserRoleLevel();
    }

    public function getCurrentUserRepId(): int {
        $rep_id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->tables['representatives']} WHERE user_id = %d AND status = 'active'",
            $this->user_id
        ));
        return $rep_id ? intval($rep_id) : 0;
    }

    public function getUserRoleLevel(): int {
        $rep = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT role FROM {$this->tables['representatives']} WHERE user_id = %d AND status = 'active'",
            $this->user_id
        ));
        return $rep ? intval($rep->role) : 5;
    }

    /**
     * Customer Demographics Analysis
     */
    public function getCustomerDemographics($filters = []) {
        $conditions = ["1=1"];
        $params = [];

        // Apply date filters
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(c.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        // Age group analysis
        $age_groups = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                CASE 
                    WHEN YEAR(CURDATE()) - YEAR(c.birth_date) BETWEEN 18 AND 25 THEN '18-25'
                    WHEN YEAR(CURDATE()) - YEAR(c.birth_date) BETWEEN 26 AND 35 THEN '26-35'
                    WHEN YEAR(CURDATE()) - YEAR(c.birth_date) BETWEEN 36 AND 50 THEN '36-50'
                    WHEN YEAR(CURDATE()) - YEAR(c.birth_date) > 50 THEN '50+'
                    ELSE 'Bilinmiyor'
                END as age_group,
                COUNT(*) as count,
                AVG(COALESCE(p.premium_amount, 0)) as avg_premium
            FROM {$this->tables['customers']} c
            LEFT JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE {$where_clause}
            GROUP BY age_group
        ", $params));

        // Gender distribution
        $gender_distribution = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                c.gender,
                COUNT(*) as count,
                COUNT(p.id) as policy_count,
                SUM(COALESCE(p.premium_amount, 0)) as total_premium
            FROM {$this->tables['customers']} c
            LEFT JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE {$where_clause}
            GROUP BY c.gender
        ", $params));

        // Marital status analysis
        $marital_status = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                c.marital_status,
                COUNT(*) as count,
                AVG(COALESCE(p.premium_amount, 0)) as avg_premium
            FROM {$this->tables['customers']} c
            LEFT JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE {$where_clause}
            GROUP BY c.marital_status
        ", $params));

        return [
            'age_groups' => $age_groups,
            'gender_distribution' => $gender_distribution,
            'marital_status' => $marital_status
        ];
    }

    /**
     * VIP Customer Analysis
     */
    public function getVIPCustomers($filters = []) {
        $conditions = ["c.marital_status = 'Evli'"];
        $params = [];

        // Apply additional filters
        if (!empty($filters['min_premium'])) {
            $conditions[] = "total_premium >= %d";
            $params[] = $filters['min_premium'];
        }

        if (!empty($filters['min_duration'])) {
            $conditions[] = "customer_duration >= %d";
            $params[] = $filters['min_duration'];
        }

        $where_clause = implode(' AND ', $conditions);

        $vip_customers = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                c.id,
                CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                c.phone,
                c.email,
                COUNT(p.id) as policy_count,
                SUM(p.premium_amount) as total_premium,
                DATEDIFF(CURDATE(), c.created_at) as customer_duration,
                AVG(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) * 100 as renewal_rate
            FROM {$this->tables['customers']} c
            LEFT JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE {$where_clause}
            GROUP BY c.id
            HAVING total_premium > 5000 AND customer_duration > 730
            ORDER BY total_premium DESC, renewal_rate DESC
            LIMIT 50
        ", $params));

        return $vip_customers;
    }

    /**
     * Risk Analysis
     */
    public function getRiskAnalysis($filters = []) {
        // Customers at risk of churn
        $churn_risk = $this->wpdb->get_results("
            SELECT 
                c.id,
                CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                p.policy_number,
                p.policy_type,
                p.end_date,
                DATEDIFF(p.end_date, CURDATE()) as days_to_renewal,
                p.premium_amount,
                CASE 
                    WHEN DATEDIFF(p.end_date, CURDATE()) <= 30 THEN 'Yüksek'
                    WHEN DATEDIFF(p.end_date, CURDATE()) <= 60 THEN 'Orta'
                    ELSE 'Düşük'
                END as risk_level
            FROM {$this->tables['customers']} c
            INNER JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE p.status = 'active' 
            AND p.end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            ORDER BY p.end_date ASC
        ");

        // Payment delays
        $payment_delays = $this->wpdb->get_results("
            SELECT 
                c.id,
                CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                p.policy_number,
                p.policy_type,
                p.premium_amount,
                'Ödeme Gecikmesi' as risk_type
            FROM {$this->tables['customers']} c
            INNER JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE p.status = 'active' 
            AND p.payment_status = 'overdue'
            ORDER BY p.premium_amount DESC
        ");

        return [
            'churn_risk' => $churn_risk,
            'payment_delays' => $payment_delays
        ];
    }

    /**
     * Policy Performance Analysis
     */
    public function getPolicyPerformance($filters = []) {
        $conditions = ["1=1"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(p.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        // Policy type distribution
        $policy_distribution = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                p.policy_type,
                COUNT(*) as count,
                SUM(p.premium_amount) as total_premium,
                AVG(p.premium_amount) as avg_premium,
                COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_count,
                COUNT(CASE WHEN p.status = 'cancelled' THEN 1 END) as cancelled_count
            FROM {$this->tables['policies']} p
            WHERE {$where_clause}
            GROUP BY p.policy_type
            ORDER BY total_premium DESC
        ", $params));

        // Premium trend over time
        $premium_trend = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                DATE_FORMAT(p.created_at, '%%Y-%%m') as month,
                COUNT(*) as policy_count,
                SUM(p.premium_amount) as total_premium
            FROM {$this->tables['policies']} p
            WHERE {$where_clause}
            GROUP BY DATE_FORMAT(p.created_at, '%%Y-%%m')
            ORDER BY month ASC
        ", $params));

        return [
            'policy_distribution' => $policy_distribution,
            'premium_trend' => $premium_trend
        ];
    }

    /**
     * Representative Performance
     */
    public function getRepresentativePerformance($filters = []) {
        $conditions = ["r.status = 'active'"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(p.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        $performance = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                r.id,
                CONCAT(u.display_name, ' (', r.role, ')') as rep_name,
                COUNT(p.id) as policy_count,
                SUM(p.premium_amount) as total_premium,
                COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_policies,
                COUNT(DISTINCT p.customer_id) as unique_customers,
                AVG(p.premium_amount) as avg_premium
            FROM {$this->tables['representatives']} r
            LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
            LEFT JOIN {$this->tables['policies']} p ON r.id = p.representative_id
            WHERE {$where_clause}
            GROUP BY r.id
            ORDER BY total_premium DESC
        ", $params));

        return $performance;
    }

    /**
     * Quote Conversion Analysis
     */
    public function getQuoteConversion($filters = []) {
        $conditions = ["1=1"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(o.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        $conversion_stats = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                o.policy_type,
                COUNT(*) as total_quotes,
                COUNT(p.id) as converted_policies,
                (COUNT(p.id) / COUNT(*)) * 100 as conversion_rate,
                AVG(o.premium_amount) as avg_quote_amount,
                AVG(p.premium_amount) as avg_converted_amount
            FROM {$this->tables['offers']} o
            LEFT JOIN {$this->tables['policies']} p ON o.id = p.offer_id
            WHERE {$where_clause}
            GROUP BY o.policy_type
            ORDER BY conversion_rate DESC
        ", $params));

        return $conversion_stats;
    }

    /**
     * Profitability Analysis
     */
    public function getProfitabilityAnalysis($filters = []) {
        // This is a simplified profitability calculation
        // In real implementation, you'd include actual cost data
        
        $conditions = ["p.status = 'active'"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(p.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        $profitability = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                p.policy_type,
                COUNT(*) as policy_count,
                SUM(p.premium_amount) as total_revenue,
                AVG(p.premium_amount) as avg_revenue_per_policy,
                SUM(p.premium_amount * 0.15) as estimated_commission,
                SUM(p.premium_amount * 0.85) as estimated_cost
            FROM {$this->tables['policies']} p
            WHERE {$where_clause}
            GROUP BY p.policy_type
            ORDER BY total_revenue DESC
        ", $params));

        return $profitability;
    }

    /**
     * Geographic Distribution
     */
    public function getGeographicDistribution($filters = []) {
        $conditions = ["1=1"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(c.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        $geographic_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                c.city,
                c.district,
                COUNT(*) as customer_count,
                COUNT(p.id) as policy_count,
                SUM(p.premium_amount) as total_premium,
                AVG(p.premium_amount) as avg_premium
            FROM {$this->tables['customers']} c
            LEFT JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE {$where_clause}
            GROUP BY c.city, c.district
            ORDER BY total_premium DESC
            LIMIT 20
        ", $params));

        return $geographic_data;
    }
}

// Initialize the reports manager
$reports_manager = new NextGenReportsManager();

// Handle AJAX requests
if (isset($_POST['action']) && $_POST['action'] === 'get_report_data') {
    check_ajax_referer('reports_nonce', 'nonce');
    
    $report_type = sanitize_text_field($_POST['report_type']);
    $filters = isset($_POST['filters']) ? array_map('sanitize_text_field', $_POST['filters']) : [];
    
    $data = [];
    switch ($report_type) {
        case 'customer_demographics':
            $data = $reports_manager->getCustomerDemographics($filters);
            break;
        case 'vip_customers':
            $data = $reports_manager->getVIPCustomers($filters);
            break;
        case 'risk_analysis':
            $data = $reports_manager->getRiskAnalysis($filters);
            break;
        case 'policy_performance':
            $data = $reports_manager->getPolicyPerformance($filters);
            break;
        case 'representative_performance':
            $data = $reports_manager->getRepresentativePerformance($filters);
            break;
        case 'quote_conversion':
            $data = $reports_manager->getQuoteConversion($filters);
            break;
        case 'profitability':
            $data = $reports_manager->getProfitabilityAnalysis($filters);
            break;
        case 'geographic':
            $data = $reports_manager->getGeographicDistribution($filters);
            break;
    }
    
    wp_send_json_success($data);
    wp_die();
}

// Handle export requests
if (isset($_GET['export'])) {
    $export_type = sanitize_text_field($_GET['export']);
    $report_type = sanitize_text_field($_GET['report_type'] ?? 'dashboard');
    
    // Export functionality will be implemented here
    // For now, just redirect back
    wp_redirect(remove_query_arg(['export', 'report_type']));
    exit;
}

// Get initial data for dashboard
$dashboard_data = [
    'customer_demographics' => $reports_manager->getCustomerDemographics(),
    'policy_performance' => $reports_manager->getPolicyPerformance(),
    'representative_performance' => $reports_manager->getRepresentativePerformance(),
    'quote_conversion' => $reports_manager->getQuoteConversion()
];

?>

<!DOCTYPE html>
<html lang="tr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html(__('Yeni Nesil Raporlar - CRM Dashboard', 'insurance-crm')); ?></title>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- D3.js -->
    <script src="https://d3js.org/d3.v7.min.js"></script>
    
    <style>
        :root {
            --primary-color: #3b82f6;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #06b6d4;
            
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
        }

        [data-theme="dark"] {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --bg-tertiary: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --border-color: #475569;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .reports-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
            min-height: 100vh;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            background: var(--bg-primary);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
        }

        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .header-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .theme-toggle {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: var(--primary-color);
            color: white;
        }

        .filters-panel {
            background: var(--bg-primary);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .filter-group input,
        .filter-group select {
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
            color: var(--text-primary);
            transition: border-color 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgb(59 130 246 / 0.1);
        }

        .filters-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--bg-secondary);
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .dashboard-card {
            background: var(--bg-primary);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .card-actions {
            display: flex;
            gap: 0.5rem;
        }

        .card-content {
            height: 300px;
            position: relative;
        }

        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            background: var(--bg-primary);
            padding: 1rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
        }

        .tab {
            padding: 0.75rem 1.5rem;
            border: none;
            background: transparent;
            color: var(--text-secondary);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }

        .tab.active {
            background: var(--primary-color);
            color: white;
        }

        .tab:hover:not(.active) {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .export-panel {
            background: var(--bg-primary);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            margin-top: 2rem;
        }

        .export-options {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .loading.show {
            display: flex;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--bg-tertiary);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-primary);
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .data-table th,
        .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
        }

        .data-table tbody tr:hover {
            background: var(--bg-secondary);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .reports-container {
                padding: 1rem;
            }

            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .export-options {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="reports-container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo esc_html(__('Yeni Nesil Raporlar', 'insurance-crm')); ?></h1>
            <div class="header-controls">
                <button class="theme-toggle" onclick="toggleTheme()">
                    🌙 Koyu Tema
                </button>
                <button class="btn btn-primary" onclick="refreshDashboard()">
                    🔄 Yenile
                </button>
            </div>
        </div>

        <!-- Filters Panel -->
        <div class="filters-panel">
            <div class="filters-grid">
                <div class="filter-group">
                    <label><?php echo esc_html(__('Başlangıç Tarihi', 'insurance-crm')); ?></label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-01'); ?>">
                </div>
                <div class="filter-group">
                    <label><?php echo esc_html(__('Bitiş Tarihi', 'insurance-crm')); ?></label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="filter-group">
                    <label><?php echo esc_html(__('Poliçe Türü', 'insurance-crm')); ?></label>
                    <select id="policy_type" name="policy_type">
                        <option value=""><?php echo esc_html(__('Tümü', 'insurance-crm')); ?></option>
                        <option value="trafik"><?php echo esc_html(__('Trafik Sigortası', 'insurance-crm')); ?></option>
                        <option value="kasko"><?php echo esc_html(__('Kasko', 'insurance-crm')); ?></option>
                        <option value="konut"><?php echo esc_html(__('Konut Sigortası', 'insurance-crm')); ?></option>
                        <option value="dask"><?php echo esc_html(__('DASK', 'insurance-crm')); ?></option>
                        <option value="saglik"><?php echo esc_html(__('Sağlık Sigortası', 'insurance-crm')); ?></option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php echo esc_html(__('Müşteri Yaş Grubu', 'insurance-crm')); ?></label>
                    <select id="age_group" name="age_group">
                        <option value=""><?php echo esc_html(__('Tümü', 'insurance-crm')); ?></option>
                        <option value="18-25">18-25</option>
                        <option value="26-35">26-35</option>
                        <option value="36-50">36-50</option>
                        <option value="50+">50+</option>
                    </select>
                </div>
            </div>
            <div class="filters-actions">
                <button class="btn btn-secondary" onclick="clearFilters()">
                    ❌ Temizle
                </button>
                <button class="btn btn-primary" onclick="applyFilters()">
                    🔍 Filtrele
                </button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('dashboard')"><?php echo esc_html(__('Dashboard', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('customers')"><?php echo esc_html(__('Müşteri Analizi', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('policies')"><?php echo esc_html(__('Poliçe Performansı', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('representatives')"><?php echo esc_html(__('Temsilci Performansı', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('vip')"><?php echo esc_html(__('VIP Müşteriler', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('risk')"><?php echo esc_html(__('Risk Analizi', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('profitability')"><?php echo esc_html(__('Karlılık', 'insurance-crm')); ?></button>
        </div>

        <!-- Dashboard Content -->
        <div id="dashboard-content">
            <!-- Content will be loaded here dynamically -->
        </div>

        <!-- Export Panel -->
        <div class="export-panel">
            <h3><?php echo esc_html(__('Rapor Dışa Aktarım', 'insurance-crm')); ?></h3>
            <div class="export-options">
                <button class="btn btn-primary" onclick="exportReport('pdf')">
                    📄 PDF
                </button>
                <button class="btn btn-primary" onclick="exportReport('excel')">
                    📊 Excel
                </button>
                <button class="btn btn-primary" onclick="exportReport('powerpoint')">
                    📈 PowerPoint
                </button>
                <button class="btn btn-primary" onclick="exportReport('csv')">
                    📋 CSV
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading" id="loading">
        <div class="loading-spinner"></div>
    </div>

    <script>
        /**
         * Next Generation Reports JavaScript
         * @version 10.0.0
         */
        class NextGenReports {
            constructor() {
                this.currentTab = 'dashboard';
                this.charts = {};
                this.data = <?php echo json_encode($dashboard_data); ?>;
                this.filters = {};
                this.theme = localStorage.getItem('reports-theme') || 'light';
                
                this.init();
            }

            init() {
                this.applyTheme();
                this.loadDashboard();
                this.initRealTimeUpdates();
            }

            applyTheme() {
                document.documentElement.setAttribute('data-theme', this.theme);
                const themeButton = document.querySelector('.theme-toggle');
                if (themeButton) {
                    themeButton.textContent = this.theme === 'light' ? '🌙 Koyu Tema' : '☀️ Açık Tema';
                }
            }

            loadDashboard() {
                const content = document.getElementById('dashboard-content');
                
                switch (this.currentTab) {
                    case 'dashboard':
                        this.renderDashboard(content);
                        break;
                    case 'customers':
                        this.renderCustomerAnalysis(content);
                        break;
                    case 'policies':
                        this.renderPolicyPerformance(content);
                        break;
                    case 'representatives':
                        this.renderRepresentativePerformance(content);
                        break;
                    case 'vip':
                        this.renderVIPCustomers(content);
                        break;
                    case 'risk':
                        this.renderRiskAnalysis(content);
                        break;
                    case 'profitability':
                        this.renderProfitabilityAnalysis(content);
                        break;
                }
            }

            renderDashboard(container) {
                container.innerHTML = `
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value">1,234</div>
                            <div class="stat-label">Toplam Müşteri</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₺2.5M</div>
                            <div class="stat-label">Toplam Prim</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">567</div>
                            <div class="stat-label">Aktif Poliçe</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">%89</div>
                            <div class="stat-label">Yenileme Oranı</div>
                        </div>
                    </div>
                    
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Müşteri Yaş Dağılımı</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="age-distribution-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Poliçe Türü Dağılımı</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="policy-type-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Aylık Prim Trendi</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="premium-trend-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Temsilci Performansı</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="representative-performance-chart"></canvas>
                            </div>
                        </div>
                    </div>
                `;

                // Initialize charts
                this.initCharts();
            }

            renderCustomerAnalysis(container) {
                container.innerHTML = `
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Demografik Segmentasyon</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="demographics-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Müşteri Yaşam Döngüsü</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="lifecycle-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Coğrafi Dağılım</h3>
                            </div>
                            <div class="card-content">
                                <div id="geographic-map"></div>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">En Değerli Müşteriler</h3>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Müşteri</th>
                                            <th>Poliçe Sayısı</th>
                                            <th>Toplam Prim</th>
                                            <th>CLV</th>
                                        </tr>
                                    </thead>
                                    <tbody id="top-customers-table">
                                        <!-- Data will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                this.initCustomerCharts();
            }

            renderVIPCustomers(container) {
                container.innerHTML = `
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3 class="card-title">VIP Müşteri Kriterleri</h3>
                        </div>
                        <div class="card-content">
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label>Minimum Prim Tutarı</label>
                                    <input type="number" id="vip-min-premium" value="5000" min="0">
                                </div>
                                <div class="filter-group">
                                    <label>Minimum Müşteri Süresi (Gün)</label>
                                    <input type="number" id="vip-min-duration" value="730" min="0">
                                </div>
                                <div class="filter-group">
                                    <label>Medeni Durum</label>
                                    <select id="vip-marital-status">
                                        <option value="">Tümü</option>
                                        <option value="Evli" selected>Evli</option>
                                        <option value="Bekar">Bekar</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3 class="card-title">VIP Müşteri Listesi</h3>
                            <button class="btn btn-primary" onclick="loadVIPCustomers()">Güncelle</button>
                        </div>
                        <div class="card-content">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Müşteri Adı</th>
                                        <th>Telefon</th>
                                        <th>Poliçe Sayısı</th>
                                        <th>Toplam Prim</th>
                                        <th>Müşteri Süresi</th>
                                        <th>Yenileme Oranı</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody id="vip-customers-table">
                                    <!-- Data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;

                this.loadVIPCustomers();
            }

            renderRiskAnalysis(container) {
                container.innerHTML = `
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Risk Seviyeleri</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="risk-levels-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Yenileme Riski</h3>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Müşteri</th>
                                            <th>Poliçe No</th>
                                            <th>Bitiş Tarihi</th>
                                            <th>Risk Seviyesi</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="renewal-risk-table">
                                        <!-- Data will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                this.initRiskCharts();
            }

            initCharts() {
                // Age Distribution Chart
                const ageCtx = document.getElementById('age-distribution-chart')?.getContext('2d');
                if (ageCtx) {
                    this.charts.ageDistribution = new Chart(ageCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['18-25', '26-35', '36-50', '50+'],
                            datasets: [{
                                data: [15, 35, 30, 20],
                                backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }

                // Policy Type Chart
                const policyCtx = document.getElementById('policy-type-chart')?.getContext('2d');
                if (policyCtx) {
                    this.charts.policyType = new Chart(policyCtx, {
                        type: 'pie',
                        data: {
                            labels: ['Trafik', 'Kasko', 'Konut', 'DASK', 'Sağlık'],
                            datasets: [{
                                data: [40, 25, 15, 10, 10],
                                backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }

                // Premium Trend Chart
                const trendCtx = document.getElementById('premium-trend-chart')?.getContext('2d');
                if (trendCtx) {
                    this.charts.premiumTrend = new Chart(trendCtx, {
                        type: 'line',
                        data: {
                            labels: ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz'],
                            datasets: [{
                                label: 'Toplam Prim',
                                data: [120000, 150000, 180000, 220000, 280000, 320000],
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '₺' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Representative Performance Chart
                const repCtx = document.getElementById('representative-performance-chart')?.getContext('2d');
                if (repCtx) {
                    this.charts.representativePerformance = new Chart(repCtx, {
                        type: 'bar',
                        data: {
                            labels: ['Ahmet K.', 'Ayşe M.', 'Mehmet D.', 'Fatma S.', 'Ali Y.'],
                            datasets: [{
                                label: 'Toplam Prim',
                                data: [85000, 92000, 78000, 105000, 67000],
                                backgroundColor: '#10b981'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '₺' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            initCustomerCharts() {
                // Demographics Chart
                const demoCtx = document.getElementById('demographics-chart')?.getContext('2d');
                if (demoCtx) {
                    this.charts.demographics = new Chart(demoCtx, {
                        type: 'radar',
                        data: {
                            labels: ['18-25 Yaş', '26-35 Yaş', '36-50 Yaş', '50+ Yaş', 'Erkek', 'Kadın'],
                            datasets: [{
                                label: 'Müşteri Dağılımı',
                                data: [15, 35, 30, 20, 55, 45],
                                backgroundColor: 'rgba(59, 130, 246, 0.2)',
                                borderColor: '#3b82f6',
                                pointBackgroundColor: '#3b82f6'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }

                // Customer Lifecycle Chart
                const lifecycleCtx = document.getElementById('lifecycle-chart')?.getContext('2d');
                if (lifecycleCtx) {
                    this.charts.lifecycle = new Chart(lifecycleCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Yeni', 'Mevcut', 'Sadık', 'Risk Altında'],
                            datasets: [{
                                data: [25, 45, 20, 10],
                                backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom'
                                }
                            }
                        }
                    });
                }
            }

            initRiskCharts() {
                const riskCtx = document.getElementById('risk-levels-chart')?.getContext('2d');
                if (riskCtx) {
                    this.charts.riskLevels = new Chart(riskCtx, {
                        type: 'bar',
                        data: {
                            labels: ['Düşük Risk', 'Orta Risk', 'Yüksek Risk'],
                            datasets: [{
                                label: 'Müşteri Sayısı',
                                data: [150, 75, 25],
                                backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                }
            }

            loadVIPCustomers() {
                // This would normally make an AJAX call to get VIP customer data
                const tableBody = document.getElementById('vip-customers-table');
                if (tableBody) {
                    tableBody.innerHTML = `
                        <tr>
                            <td>Mehmet Özkan</td>
                            <td>0532 123 4567</td>
                            <td>3</td>
                            <td>₺15,000</td>
                            <td>892 gün</td>
                            <td>%95</td>
                            <td><button class="btn btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Detay</button></td>
                        </tr>
                        <tr>
                            <td>Ayşe Kaya</td>
                            <td>0533 987 6543</td>
                            <td>2</td>
                            <td>₺12,500</td>
                            <td>756 gün</td>
                            <td>%100</td>
                            <td><button class="btn btn-primary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">Detay</button></td>
                        </tr>
                    `;
                }
            }

            initRealTimeUpdates() {
                // Update dashboard every 30 seconds
                setInterval(() => {
                    if (this.currentTab === 'dashboard') {
                        this.updateRealTimeData();
                    }
                }, 30000);
            }

            updateRealTimeData() {
                // Update stat cards with new data
                const statValues = document.querySelectorAll('.stat-value');
                statValues.forEach(element => {
                    // Add a subtle flash effect to show update
                    element.style.background = '#10b981';
                    element.style.color = 'white';
                    element.style.borderRadius = '4px';
                    element.style.padding = '2px 6px';
                    
                    setTimeout(() => {
                        element.style.background = '';
                        element.style.color = '';
                        element.style.borderRadius = '';
                        element.style.padding = '';
                    }, 1000);
                });
            }
        }

        // Global functions
        function toggleTheme() {
            const app = window.reportsApp;
            app.theme = app.theme === 'light' ? 'dark' : 'light';
            localStorage.setItem('reports-theme', app.theme);
            app.applyTheme();
        }

        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');

            // Update content
            const app = window.reportsApp;
            app.currentTab = tabName;
            app.loadDashboard();
        }

        function applyFilters() {
            const filters = {
                start_date: document.getElementById('start_date').value,
                end_date: document.getElementById('end_date').value,
                policy_type: document.getElementById('policy_type').value,
                age_group: document.getElementById('age_group').value
            };

            showLoading();
            
            // Simulate API call
            setTimeout(() => {
                hideLoading();
                window.reportsApp.filters = filters;
                window.reportsApp.loadDashboard();
            }, 1000);
        }

        function clearFilters() {
            document.getElementById('start_date').value = '<?php echo date('Y-m-01'); ?>';
            document.getElementById('end_date').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('policy_type').value = '';
            document.getElementById('age_group').value = '';
            
            window.reportsApp.filters = {};
            window.reportsApp.loadDashboard();
        }

        function refreshDashboard() {
            showLoading();
            setTimeout(() => {
                hideLoading();
                window.reportsApp.loadDashboard();
            }, 1000);
        }

        function exportReport(format) {
            showLoading();
            
            // Simulate export process
            setTimeout(() => {
                hideLoading();
                alert(`${format.toUpperCase()} raporu hazırlanıyor. İndirme başlayacak...`);
                
                // In real implementation, this would trigger actual export
                const url = `<?php echo admin_url('admin.php?page=insurance-crm-reports'); ?>&export=${format}&report_type=${window.reportsApp.currentTab}`;
                window.open(url, '_blank');
            }, 2000);
        }

        function loadVIPCustomers() {
            const app = window.reportsApp;
            const filters = {
                min_premium: document.getElementById('vip-min-premium')?.value || 5000,
                min_duration: document.getElementById('vip-min-duration')?.value || 730,
                marital_status: document.getElementById('vip-marital-status')?.value || ''
            };

            showLoading();
            
            // Simulate API call
            setTimeout(() => {
                hideLoading();
                app.loadVIPCustomers();
            }, 1000);
        }

        function showLoading() {
            document.getElementById('loading').classList.add('show');
        }

        function hideLoading() {
            document.getElementById('loading').classList.remove('show');
        }

        // Initialize the application
        document.addEventListener('DOMContentLoaded', function() {
            window.reportsApp = new NextGenReports();
        });
    </script>
</body>
</html>