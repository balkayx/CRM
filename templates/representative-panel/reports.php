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
     * Get profitability analysis
     */
    public function getProfitabilityAnalysis($filters = []) {
        $conditions = ["p.status = 'active'"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(p.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        // Total revenue (premium amounts)
        $total_revenue = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT COALESCE(SUM(p.premium_amount), 0)
            FROM {$this->tables['policies']} p
            WHERE {$where_clause}
        ", $params));

        // Total commission (assuming 15% commission rate)
        $commission_rate = 0.15;
        $total_commission = $total_revenue * $commission_rate;

        // Policy count for average calculations
        $policy_count = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*)
            FROM {$this->tables['policies']} p
            WHERE {$where_clause}
        ", $params));

        // Average profit per policy
        $avg_profit_per_policy = $policy_count > 0 ? ($total_commission / $policy_count) : 0;

        // Profit margin (commission rate)
        $profit_margin = $commission_rate * 100;

        return [
            'total_revenue' => floatval($total_revenue),
            'total_commission' => floatval($total_commission),
            'profit_margin' => floatval($profit_margin),
            'avg_profit_per_policy' => floatval($avg_profit_per_policy),
            'policy_count' => intval($policy_count)
        ];
    }

    /**
     * Get monthly premium trends
     */
    public function getMonthlyPremiumTrends($filters = []) {
        $conditions = ["p.status = 'active'"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(p.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        } else {
            // Default to last 6 months
            $conditions[] = "p.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
        }

        $where_clause = implode(' AND ', $conditions);

        $trends = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                DATE_FORMAT(p.created_at, '%Y-%m') as month,
                MONTHNAME(p.created_at) as month_name,
                SUM(p.premium_amount) as total_premium,
                COUNT(p.id) as policy_count
            FROM {$this->tables['policies']} p
            WHERE {$where_clause}
            GROUP BY DATE_FORMAT(p.created_at, '%Y-%m'), MONTHNAME(p.created_at)
            ORDER BY month ASC
        ", $params));

        return $trends;
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStats() {
        // Total customers
        $total_customers = $this->wpdb->get_var("
            SELECT COUNT(*) FROM {$this->tables['customers']} 
            WHERE status != 'deleted'
        ");
        
        // Total premium amount
        $total_premium = $this->wpdb->get_var("
            SELECT COALESCE(SUM(premium_amount), 0) FROM {$this->tables['policies']} 
            WHERE status = 'active'
        ");
        
        // Active policies count
        $active_policies = $this->wpdb->get_var("
            SELECT COUNT(*) FROM {$this->tables['policies']} 
            WHERE status = 'active'
        ");
        
        // Renewal rate calculation (policies renewed vs expiring)
        $renewal_rate = $this->wpdb->get_var("
            SELECT 
                ROUND(
                    (COUNT(CASE WHEN renewed = 1 THEN 1 END) * 100.0 / 
                     NULLIF(COUNT(CASE WHEN end_date < CURDATE() THEN 1 END), 0)), 2
                ) 
            FROM {$this->tables['policies']}
        ");
        
        return [
            'total_customers' => intval($total_customers),
            'total_premium' => floatval($total_premium),
            'active_policies' => intval($active_policies),
            'renewal_rate' => floatval($renewal_rate) ?: 0
        ];
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

    /**
     * Customer Lifetime Value Analysis
     */
    public function getCustomerLifetimeValue($filters = []) {
        $conditions = ["1=1"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(c.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        $clv_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                c.id,
                CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                COUNT(p.id) as total_policies,
                SUM(p.premium_amount) as total_revenue,
                AVG(p.premium_amount) as avg_policy_value,
                DATEDIFF(CURDATE(), c.created_at) as customer_age_days,
                (SUM(p.premium_amount) / (DATEDIFF(CURDATE(), c.created_at) / 365.25)) as annual_value,
                SUM(p.premium_amount) * 1.2 as estimated_clv,
                CASE 
                    WHEN SUM(p.premium_amount) > 15000 THEN 'Yüksek'
                    WHEN SUM(p.premium_amount) > 8000 THEN 'Orta'
                    ELSE 'Düşük'
                END as clv_segment
            FROM {$this->tables['customers']} c
            LEFT JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE {$where_clause}
            GROUP BY c.id
            HAVING total_policies > 0
            ORDER BY estimated_clv DESC
            LIMIT 100
        ", $params));

        return $clv_data;
    }

    /**
     * Advanced Market Analysis
     */
    public function getMarketAnalysis($filters = []) {
        $conditions = ["1=1"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(p.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        // Market penetration by region
        $penetration = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                c.city,
                COUNT(DISTINCT c.id) as total_customers,
                COUNT(p.id) as total_policies,
                SUM(p.premium_amount) as market_size,
                (COUNT(p.id) / COUNT(DISTINCT c.id)) as penetration_rate,
                AVG(p.premium_amount) as avg_premium_in_market
            FROM {$this->tables['customers']} c
            LEFT JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE {$where_clause}
            GROUP BY c.city
            HAVING total_customers >= 10
            ORDER BY market_size DESC
        ", $params));

        // Growth trends
        $growth_trends = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                DATE_FORMAT(p.created_at, '%%Y-%%m') as month,
                p.policy_type,
                COUNT(*) as new_policies,
                SUM(p.premium_amount) as monthly_premium,
                LAG(COUNT(*)) OVER (PARTITION BY p.policy_type ORDER BY DATE_FORMAT(p.created_at, '%%Y-%%m')) as prev_month_policies
            FROM {$this->tables['policies']} p
            WHERE {$where_clause}
            GROUP BY DATE_FORMAT(p.created_at, '%%Y-%%m'), p.policy_type
            ORDER BY month DESC, p.policy_type
            LIMIT 60
        ", $params));

        return [
            'penetration' => $penetration,
            'growth_trends' => $growth_trends
        ];
    }

    /**
     * Task Performance Analytics
     */
    public function getTaskPerformanceAnalytics($filters = []) {
        $conditions = ["1=1"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(t.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        $task_performance = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                r.id as rep_id,
                CONCAT(u.display_name, ' (', r.role, ')') as rep_name,
                COUNT(t.id) as total_tasks,
                COUNT(CASE WHEN t.status = 'completed' THEN 1 END) as completed_tasks,
                COUNT(CASE WHEN t.status = 'pending' THEN 1 END) as pending_tasks,
                COUNT(CASE WHEN t.status = 'overdue' THEN 1 END) as overdue_tasks,
                AVG(CASE WHEN t.status = 'completed' THEN DATEDIFF(t.completed_at, t.created_at) END) as avg_completion_days,
                (COUNT(CASE WHEN t.status = 'completed' THEN 1 END) / COUNT(t.id)) * 100 as completion_rate
            FROM {$this->tables['representatives']} r
            LEFT JOIN {$this->tables['users']} u ON r.user_id = u.ID
            LEFT JOIN {$this->tables['tasks']} t ON r.id = t.representative_id
            WHERE r.status = 'active' AND {$where_clause}
            GROUP BY r.id
            ORDER BY completion_rate DESC, total_tasks DESC
        ", $params));

        return $task_performance;
    }

    /**
     * Customer Satisfaction Analysis
     */
    public function getCustomerSatisfactionAnalysis($filters = []) {
        // This would integrate with a customer feedback system
        // For now, we'll simulate satisfaction data based on renewal rates and complaint history
        
        $conditions = ["1=1"];
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $conditions[] = "DATE(c.created_at) BETWEEN %s AND %s";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        $where_clause = implode(' AND ', $conditions);

        $satisfaction_data = $this->wpdb->get_results($this->wpdb->prepare("
            SELECT 
                c.id,
                CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                COUNT(p.id) as total_policies,
                COUNT(CASE WHEN p.status = 'active' THEN 1 END) as active_policies,
                COUNT(CASE WHEN p.status = 'cancelled' THEN 1 END) as cancelled_policies,
                (COUNT(CASE WHEN p.status = 'active' THEN 1 END) / COUNT(p.id)) * 100 as retention_rate,
                CASE 
                    WHEN (COUNT(CASE WHEN p.status = 'active' THEN 1 END) / COUNT(p.id)) * 100 >= 90 THEN 'Çok Memnun'
                    WHEN (COUNT(CASE WHEN p.status = 'active' THEN 1 END) / COUNT(p.id)) * 100 >= 70 THEN 'Memnun'
                    WHEN (COUNT(CASE WHEN p.status = 'active' THEN 1 END) / COUNT(p.id)) * 100 >= 50 THEN 'Orta'
                    ELSE 'Memnun Değil'
                END as satisfaction_level
            FROM {$this->tables['customers']} c
            LEFT JOIN {$this->tables['policies']} p ON c.id = p.customer_id
            WHERE {$where_clause}
            GROUP BY c.id
            HAVING total_policies > 0
            ORDER BY retention_rate DESC
        ", $params));

        return $satisfaction_data;
    }

    /**
     * Export Data Methods
     */
    public function exportToPDF($data, $report_type) {
        // This would generate a PDF report
        // For now, return formatted data for PDF generation
        return [
            'title' => $this->getReportTitle($report_type),
            'data' => $data,
            'generated_at' => current_time('mysql'),
            'format' => 'pdf'
        ];
    }

    public function exportToExcel($data, $report_type) {
        // This would generate an Excel file
        return [
            'title' => $this->getReportTitle($report_type),
            'data' => $data,
            'generated_at' => current_time('mysql'),
            'format' => 'excel'
        ];
    }

    public function exportToPowerPoint($data, $report_type) {
        // This would generate a PowerPoint presentation
        return [
            'title' => $this->getReportTitle($report_type),
            'data' => $data,
            'generated_at' => current_time('mysql'),
            'format' => 'powerpoint'
        ];
    }

    public function exportToCSV($data, $report_type) {
        // This would generate a CSV file
        return [
            'title' => $this->getReportTitle($report_type),
            'data' => $data,
            'generated_at' => current_time('mysql'),
            'format' => 'csv'
        ];
    }

    private function getReportTitle($report_type) {
        $titles = [
            'customer_demographics' => 'Müşteri Demografik Analizi',
            'vip_customers' => 'VIP Müşteri Raporu',
            'risk_analysis' => 'Risk Analiz Raporu',
            'policy_performance' => 'Poliçe Performans Raporu',
            'representative_performance' => 'Temsilci Performans Raporu',
            'quote_conversion' => 'Teklif Dönüşüm Raporu',
            'profitability' => 'Karlılık Analiz Raporu',
            'geographic' => 'Coğrafi Dağılım Raporu',
            'market_analysis' => 'Pazar Analiz Raporu',
            'clv_analysis' => 'Müşteri Yaşam Boyu Değer Analizi',
            'task_performance' => 'Görev Performans Analizi',
            'satisfaction' => 'Müşteri Memnuniyet Analizi'
        ];

        return $titles[$report_type] ?? 'Genel Rapor';
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
        case 'market_analysis':
            $data = $reports_manager->getMarketAnalysis($filters);
            break;
        case 'clv_analysis':
            $data = $reports_manager->getCustomerLifetimeValue($filters);
            break;
        case 'task_performance':
            $data = $reports_manager->getTaskPerformanceAnalytics($filters);
            break;
        case 'satisfaction':
            $data = $reports_manager->getCustomerSatisfactionAnalysis($filters);
            break;
    }
    
    wp_send_json_success($data);
    wp_die();
}

// Handle export requests
if (isset($_GET['export'])) {
    $export_type = sanitize_text_field($_GET['export']);
    $report_type = sanitize_text_field($_GET['report_type'] ?? 'dashboard');
    $filters = isset($_GET['filters']) ? array_map('sanitize_text_field', $_GET['filters']) : [];
    
    // Get data for export
    $export_data = [];
    switch ($report_type) {
        case 'customer_demographics':
            $export_data = $reports_manager->getCustomerDemographics($filters);
            break;
        case 'vip_customers':
            $export_data = $reports_manager->getVIPCustomers($filters);
            break;
        case 'risk_analysis':
            $export_data = $reports_manager->getRiskAnalysis($filters);
            break;
        case 'policy_performance':
            $export_data = $reports_manager->getPolicyPerformance($filters);
            break;
        case 'representative_performance':
            $export_data = $reports_manager->getRepresentativePerformance($filters);
            break;
        case 'quote_conversion':
            $export_data = $reports_manager->getQuoteConversion($filters);
            break;
        case 'profitability':
            $export_data = $reports_manager->getProfitabilityAnalysis($filters);
            break;
        case 'geographic':
            $export_data = $reports_manager->getGeographicDistribution($filters);
            break;
        case 'market_analysis':
            $export_data = $reports_manager->getMarketAnalysis($filters);
            break;
        case 'clv_analysis':
            $export_data = $reports_manager->getCustomerLifetimeValue($filters);
            break;
        case 'task_performance':
            $export_data = $reports_manager->getTaskPerformanceAnalytics($filters);
            break;
        case 'satisfaction':
            $export_data = $reports_manager->getCustomerSatisfactionAnalysis($filters);
            break;
    }
    
    // Process export
    $export_result = [];
    switch ($export_type) {
        case 'pdf':
            $export_result = $reports_manager->exportToPDF($export_data, $report_type);
            break;
        case 'excel':
            $export_result = $reports_manager->exportToExcel($export_data, $report_type);
            break;
        case 'powerpoint':
            $export_result = $reports_manager->exportToPowerPoint($export_data, $report_type);
            break;
        case 'csv':
            $export_result = $reports_manager->exportToCSV($export_data, $report_type);
            break;
    }
    
    // In a real implementation, this would trigger file download
    // For now, redirect back with success message
    wp_redirect(add_query_arg(['export_success' => $export_type], remove_query_arg(['export', 'report_type'])));
    exit;
}

// Get initial data for dashboard
$dashboard_data = [
    'stats' => $reports_manager->getDashboardStats(),
    'customer_demographics' => $reports_manager->getCustomerDemographics(),
    'policy_performance' => $reports_manager->getPolicyPerformance(),
    'representative_performance' => $reports_manager->getRepresentativePerformance(),
    'quote_conversion' => $reports_manager->getQuoteConversion(),
    'monthly_trends' => $reports_manager->getMonthlyPremiumTrends(),
    'profitability' => $reports_manager->getProfitabilityAnalysis()
];

?>

<!DOCTYPE html>
<html lang="tr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html(__('Yeni Nesil Raporlar - CRM Dashboard', 'insurance-crm')); ?></title>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#3b82f6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="CRM Reports">
    <meta name="description" content="Advanced insurance CRM analytics and reporting dashboard">
    
    <!-- PWA Icons -->
    <link rel="apple-touch-icon" sizes="180x180" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 180 180'><rect width='180' height='180' fill='%233b82f6'/><text x='90' y='110' font-family='Arial' font-size='60' text-anchor='middle' fill='white'>📊</text></svg>">
    <link rel="icon" type="image/png" sizes="32x32" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' fill='%233b82f6'/><text x='16' y='24' font-family='Arial' font-size='20' text-anchor='middle' fill='white'>📊</text></svg>">
    
    <!-- Manifest -->
    <link rel="manifest" href="data:application/manifest+json,{&quot;name&quot;:&quot;CRM Reports Dashboard&quot;,&quot;short_name&quot;:&quot;CRM Reports&quot;,&quot;description&quot;:&quot;Advanced insurance CRM analytics and reporting dashboard&quot;,&quot;start_url&quot;:&quot;./&quot;,&quot;display&quot;:&quot;standalone&quot;,&quot;background_color&quot;:&quot;#ffffff&quot;,&quot;theme_color&quot;:&quot;#3b82f6&quot;,&quot;icons&quot;:[{&quot;src&quot;:&quot;data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 192 192'><rect width='192' height='192' fill='%233b82f6'/><text x='96' y='120' font-family='Arial' font-size='80' text-anchor='middle' fill='white'>📊</text></svg>&quot;,&quot;sizes&quot;:&quot;192x192&quot;,&quot;type&quot;:&quot;image/svg+xml&quot;}]}">
    
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

        /* Screen reader only class */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
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

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .notification {
            animation: slideIn 0.3s ease-out;
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
            font-size: 0.9rem;
            table-layout: fixed;
        }

        .data-table th,
        .data-table td {
            padding: 0.6rem 0.5rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            word-wrap: break-word;
            overflow: hidden;
        }

        .data-table th {
            background: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
        }

        .data-table tbody tr:hover {
            background: var(--bg-secondary);
        }

        .data-table .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            margin: 0.1rem;
        }

        .data-table a {
            font-size: 0.8rem;
            word-break: break-word;
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

            .header h1 {
                font-size: 1.5rem;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-wrap: wrap;
                overflow-x: auto;
            }

            .tab {
                flex-shrink: 0;
                white-space: nowrap;
            }

            .export-options {
                flex-direction: column;
            }

            .card-content {
                height: 250px;
            }

            .data-table {
                font-size: 0.8rem;
                table-layout: auto;
            }

            .data-table th,
            .data-table td {
                padding: 0.4rem 0.3rem;
                font-size: 0.75rem;
            }

            .data-table .btn {
                padding: 0.2rem 0.4rem;
                font-size: 0.65rem;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 1.25rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .card-content {
                height: 200px;
            }

            .btn {
                padding: 0.5rem 1rem;
                font-size: 0.875rem;
            }
        }

        /* Accessibility Improvements */
        .btn:focus,
        .tab:focus,
        .theme-toggle:focus,
        input:focus,
        select:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            :root {
                --border-color: #000000;
                --text-secondary: #000000;
            }
            
            [data-theme="dark"] {
                --border-color: #ffffff;
                --text-secondary: #ffffff;
            }
        }

        /* Print styles */
        @media print {
            .header-controls,
            .filters-panel,
            .tabs,
            .export-panel,
            .loading {
                display: none !important;
            }

            .dashboard-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            body {
                background: white !important;
                color: black !important;
            }

            .dashboard-card {
                border: 1px solid #000;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="reports-container">
        <!-- Header -->
        <div class="header">
            <h1 id="main-title"><?php echo esc_html(__('Yeni Nesil Raporlar', 'insurance-crm')); ?></h1>
            <div class="header-controls">
                <button class="theme-toggle" onclick="toggleTheme()" aria-label="Tema değiştir">
                    🌙 Koyu Tema
                </button>
                <button class="btn btn-primary" onclick="refreshDashboard()" aria-label="Dashboard'u yenile">
                    🔄 Yenile
                </button>
            </div>
        </div>

        <!-- Filters Panel -->
        <div class="filters-panel" role="region" aria-labelledby="filters-title">
            <h2 id="filters-title" class="sr-only">Filtreler</h2>
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="start_date"><?php echo esc_html(__('Başlangıç Tarihi', 'insurance-crm')); ?></label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo date('Y-m-01'); ?>" aria-describedby="start_date_help">
                    <div id="start_date_help" class="sr-only">Rapor başlangıç tarihini seçin</div>
                </div>
                <div class="filter-group">
                    <label for="end_date"><?php echo esc_html(__('Bitiş Tarihi', 'insurance-crm')); ?></label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo date('Y-m-d'); ?>" aria-describedby="end_date_help">
                    <div id="end_date_help" class="sr-only">Rapor bitiş tarihini seçin</div>
                </div>
                <div class="filter-group">
                    <label for="policy_type"><?php echo esc_html(__('Poliçe Türü', 'insurance-crm')); ?></label>
                    <select id="policy_type" name="policy_type" aria-describedby="policy_type_help">
                        <option value=""><?php echo esc_html(__('Tümü', 'insurance-crm')); ?></option>
                        <option value="trafik"><?php echo esc_html(__('Trafik Sigortası', 'insurance-crm')); ?></option>
                        <option value="kasko"><?php echo esc_html(__('Kasko', 'insurance-crm')); ?></option>
                        <option value="konut"><?php echo esc_html(__('Konut Sigortası', 'insurance-crm')); ?></option>
                        <option value="dask"><?php echo esc_html(__('DASK', 'insurance-crm')); ?></option>
                        <option value="saglik"><?php echo esc_html(__('Sağlık Sigortası', 'insurance-crm')); ?></option>
                    </select>
                    <div id="policy_type_help" class="sr-only">Raporda gösterilecek poliçe türünü seçin</div>
                </div>
                <div class="filter-group">
                    <label><?php echo esc_html(__('Gelir Seviyesi', 'insurance-crm')); ?></label>
                    <select id="income_level" name="income_level">
                        <option value=""><?php echo esc_html(__('Tümü', 'insurance-crm')); ?></option>
                        <option value="low"><?php echo esc_html(__('Düşük (0-3000)', 'insurance-crm')); ?></option>
                        <option value="medium"><?php echo esc_html(__('Orta (3000-8000)', 'insurance-crm')); ?></option>
                        <option value="high"><?php echo esc_html(__('Yüksek (8000+)', 'insurance-crm')); ?></option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php echo esc_html(__('Şehir', 'insurance-crm')); ?></label>
                    <select id="city" name="city">
                        <option value=""><?php echo esc_html(__('Tümü', 'insurance-crm')); ?></option>
                        <option value="istanbul"><?php echo esc_html(__('İstanbul', 'insurance-crm')); ?></option>
                        <option value="ankara"><?php echo esc_html(__('Ankara', 'insurance-crm')); ?></option>
                        <option value="izmir"><?php echo esc_html(__('İzmir', 'insurance-crm')); ?></option>
                        <option value="bursa"><?php echo esc_html(__('Bursa', 'insurance-crm')); ?></option>
                    </select>
                </div>
                <div class="filter-group">
                    <label><?php echo esc_html(__('Risk Seviyesi', 'insurance-crm')); ?></label>
                    <select id="risk_level" name="risk_level">
                        <option value=""><?php echo esc_html(__('Tümü', 'insurance-crm')); ?></option>
                        <option value="low"><?php echo esc_html(__('Düşük Risk', 'insurance-crm')); ?></option>
                        <option value="medium"><?php echo esc_html(__('Orta Risk', 'insurance-crm')); ?></option>
                        <option value="high"><?php echo esc_html(__('Yüksek Risk', 'insurance-crm')); ?></option>
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
            <button class="tab" onclick="switchTab('market')"><?php echo esc_html(__('Pazar Analizi', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('clv')"><?php echo esc_html(__('CLV Analizi', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('tasks')"><?php echo esc_html(__('Görev Analizi', 'insurance-crm')); ?></button>
            <button class="tab" onclick="switchTab('satisfaction')"><?php echo esc_html(__('Memnuniyet', 'insurance-crm')); ?></button>
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
                    case 'market':
                        this.renderMarketAnalysis(content);
                        break;
                    case 'clv':
                        this.renderCLVAnalysis(content);
                        break;
                    case 'tasks':
                        this.renderTaskAnalysis(content);
                        break;
                    case 'satisfaction':
                        this.renderSatisfactionAnalysis(content);
                        break;
                }
            }

            renderDashboard(container) {
                const stats = this.data.stats || {};
                const totalCustomers = parseInt(stats.total_customers) || 0;
                const totalPremium = parseFloat(stats.total_premium) || 0;
                const activePolicies = parseInt(stats.active_policies) || 0;
                const renewalRate = parseFloat(stats.renewal_rate) || 0;
                
                container.innerHTML = `
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value">${totalCustomers.toLocaleString()}</div>
                            <div class="stat-label">Toplam Müşteri</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₺${totalPremium.toLocaleString()}</div>
                            <div class="stat-label">Toplam Prim</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">${activePolicies.toLocaleString()}</div>
                            <div class="stat-label">Aktif Poliçe</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">%${renewalRate}</div>
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
                if (ageCtx && this.data.customer_demographics) {
                    const ageData = this.data.customer_demographics.age_groups || [];
                    const labels = ageData.map(item => item.age_group);
                    const counts = ageData.map(item => parseInt(item.count) || 0);
                    const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];
                    
                    this.charts.ageDistribution = new Chart(ageCtx, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: counts,
                                backgroundColor: colors.slice(0, labels.length)
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
                if (policyCtx && this.data.policy_performance) {
                    const policyData = this.data.policy_performance.policy_types || [];
                    const labels = policyData.map(item => item.policy_type || 'Diğer');
                    const counts = policyData.map(item => parseInt(item.count) || 0);
                    const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#84cc16'];
                    
                    this.charts.policyType = new Chart(policyCtx, {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: counts,
                                backgroundColor: colors.slice(0, labels.length)
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
                if (trendCtx && this.data.monthly_trends) {
                    const monthlyData = this.data.monthly_trends || [];
                    const labels = monthlyData.map(item => item.month_name || 'Bilinmiyor');
                    const premiums = monthlyData.map(item => parseFloat(item.total_premium) || 0);
                    
                    this.charts.premiumTrend = new Chart(trendCtx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Toplam Prim',
                                data: premiums,
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                tension: 0.4,
                                fill: true
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
                if (repCtx && this.data.representative_performance) {
                    const repData = this.data.representative_performance;
                    const labels = repData.map(rep => rep.rep_name || 'Bilinmiyor');
                    const premiums = repData.map(rep => parseFloat(rep.total_premium) || 0);
                    
                    this.charts.representativePerformance = new Chart(repCtx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Toplam Prim',
                                data: premiums,
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

            loadRepresentativeTable() {
                const tableBody = document.getElementById('rep-performance-table');
                if (tableBody && this.data.representative_performance) {
                    let html = '';
                    this.data.representative_performance.forEach(rep => {
                        const avgPremium = parseFloat(rep.avg_premium) || 0;
                        const totalPremium = parseFloat(rep.total_premium) || 0;
                        const policyCount = parseInt(rep.policy_count) || 0;
                        const activePolicies = parseInt(rep.active_policies) || 0;
                        const uniqueCustomers = parseInt(rep.unique_customers) || 0;
                        
                        html += `
                            <tr>
                                <td>${rep.rep_name || 'Bilinmiyor'}</td>
                                <td>${policyCount}</td>
                                <td>₺${totalPremium.toLocaleString()}</td>
                                <td>${activePolicies}</td>
                                <td>${uniqueCustomers}</td>
                                <td>₺${avgPremium.toLocaleString()}</td>
                            </tr>
                        `;
                    });
                    
                    if (html === '') {
                        html = '<tr><td colspan="6" style="text-align: center;">Veri bulunamadı</td></tr>';
                    }
                    
                    tableBody.innerHTML = html;
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

            renderMarketAnalysis(container) {
                container.innerHTML = `
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Bölgesel Penetrasyon</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="penetration-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Büyüme Trendi</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="growth-trend-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Rekabet Analizi</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="competition-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Fırsat Alanları</h3>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Bölge</th>
                                            <th>Pazar Büyüklüğü</th>
                                            <th>Penetrasyon</th>
                                            <th>Potansiyel</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>İstanbul/Kadıköy</td>
                                            <td>₺2.5M</td>
                                            <td>%15</td>
                                            <td>Yüksek</td>
                                        </tr>
                                        <tr>
                                            <td>Ankara/Çankaya</td>
                                            <td>₺1.8M</td>
                                            <td>%22</td>
                                            <td>Orta</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                this.initMarketCharts();
            }

            renderCLVAnalysis(container) {
                container.innerHTML = `
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value">₺45,000</div>
                            <div class="stat-label">Ortalama CLV</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₺125,000</div>
                            <div class="stat-label">En Yüksek CLV</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">2.8 Yıl</div>
                            <div class="stat-label">Ortalama Müşteri Süresi</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">%15</div>
                            <div class="stat-label">Yıllık Büyüme</div>
                        </div>
                    </div>
                    
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">CLV Segmentasyonu</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="clv-segments-chart"></canvas>
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
                                            <th>CLV</th>
                                            <th>Yıllık Değer</th>
                                            <th>Segment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Premium Müşteri A</td>
                                            <td>₺125,000</td>
                                            <td>₺35,000</td>
                                            <td>Yüksek</td>
                                        </tr>
                                        <tr>
                                            <td>Premium Müşteri B</td>
                                            <td>₺98,000</td>
                                            <td>₺28,000</td>
                                            <td>Yüksek</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                this.initCLVCharts();
            }

            renderTaskAnalysis(container) {
                container.innerHTML = `
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Görev Tamamlama Oranları</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="task-completion-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Ortalama Tamamlama Süreleri</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="task-duration-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Temsilci Performansı</h3>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Temsilci</th>
                                            <th>Toplam Görev</th>
                                            <th>Tamamlanan</th>
                                            <th>Başarı Oranı</th>
                                            <th>Ort. Süre</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Ahmet Kaya</td>
                                            <td>45</td>
                                            <td>42</td>
                                            <td>%93</td>
                                            <td>2.1 gün</td>
                                        </tr>
                                        <tr>
                                            <td>Ayşe Demir</td>
                                            <td>38</td>
                                            <td>35</td>
                                            <td>%92</td>
                                            <td>1.8 gün</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                this.initTaskCharts();
            }

            renderSatisfactionAnalysis(container) {
                container.innerHTML = `
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Memnuniyet Seviyeleri</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="satisfaction-levels-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Müşteri Tutma Oranları</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="retention-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Şikayet Analizi</h3>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Kategori</th>
                                            <th>Şikayet Sayısı</th>
                                            <th>Çözülme Oranı</th>
                                            <th>Ort. Çözüm Süresi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Hasar Süreci</td>
                                            <td>12</td>
                                            <td>%100</td>
                                            <td>3.2 gün</td>
                                        </tr>
                                        <tr>
                                            <td>Poliçe Yenileme</td>
                                            <td>8</td>
                                            <td>%87</td>
                                            <td>2.1 gün</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                this.initSatisfactionCharts();
            }

            renderPolicyPerformance(container) {
                container.innerHTML = `
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Poliçe Türü Performansı</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="policy-performance-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Yenileme Oranları</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="renewal-rates-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">İptal Sebepleri</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="cancellation-reasons-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Prim Gelişimi</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="premium-evolution-chart"></canvas>
                            </div>
                        </div>
                    </div>
                `;

                this.initPolicyCharts();
            }

            renderRepresentativePerformance(container) {
                container.innerHTML = `
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Temsilci Karşılaştırması</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="rep-comparison-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Satış Pipeline</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="sales-pipeline-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Temsilci Performans Tablosu</h3>
                            </div>
                            <div class="card-content">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Temsilci</th>
                                            <th>Poliçe Sayısı</th>
                                            <th>Toplam Prim</th>
                                            <th>Aktif Poliçe</th>
                                            <th>Müşteri Sayısı</th>
                                            <th>Ort. Prim</th>
                                        </tr>
                                    </thead>
                                    <tbody id="rep-performance-table">
                                        <!-- Data will be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                `;

                this.initRepresentativeCharts();
                this.loadRepresentativeTable();
            }

            renderProfitabilityAnalysis(container) {
                const profitData = this.data.profitability || {};
                const totalRevenue = parseFloat(profitData.total_revenue) || 0;
                const totalCommission = parseFloat(profitData.total_commission) || 0;
                const profitMargin = parseFloat(profitData.profit_margin) || 0;
                const avgProfitPerPolicy = parseFloat(profitData.avg_profit_per_policy) || 0;
                
                container.innerHTML = `
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value">₺${totalRevenue.toLocaleString()}</div>
                            <div class="stat-label">Toplam Gelir</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₺${totalCommission.toLocaleString()}</div>
                            <div class="stat-label">Toplam Komisyon</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">%${profitMargin.toFixed(1)}</div>
                            <div class="stat-label">Kar Marjı</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value">₺${avgProfitPerPolicy.toLocaleString()}</div>
                            <div class="stat-label">Poliçe Başına Ort. Kar</div>
                        </div>
                    </div>
                    
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Poliçe Türü Karlılığı</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="profitability-by-type-chart"></canvas>
                            </div>
                        </div>
                        
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3 class="card-title">Aylık Karlılık Trendi</h3>
                            </div>
                            <div class="card-content">
                                <canvas id="monthly-profitability-chart"></canvas>
                            </div>
                        </div>
                    </div>
                `;

                this.initProfitabilityCharts();
            }

            // Additional chart initialization methods
            initMarketCharts() {
                // Market penetration chart
                const penetrationCtx = document.getElementById('penetration-chart')?.getContext('2d');
                if (penetrationCtx) {
                    new Chart(penetrationCtx, {
                        type: 'bar',
                        data: {
                            labels: ['İstanbul', 'Ankara', 'İzmir', 'Bursa', 'Antalya'],
                            datasets: [{
                                label: 'Penetrasyon Oranı (%)',
                                data: [15, 22, 18, 12, 8],
                                backgroundColor: '#3b82f6'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            }

            initCLVCharts() {
                // CLV segments chart
                const clvCtx = document.getElementById('clv-segments-chart')?.getContext('2d');
                if (clvCtx) {
                    new Chart(clvCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Yüksek CLV', 'Orta CLV', 'Düşük CLV'],
                            datasets: [{
                                data: [20, 45, 35],
                                backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            }

            initTaskCharts() {
                // Task completion chart
                const taskCtx = document.getElementById('task-completion-chart')?.getContext('2d');
                if (taskCtx) {
                    new Chart(taskCtx, {
                        type: 'bar',
                        data: {
                            labels: ['Tamamlanan', 'Bekleyen', 'Geciken'],
                            datasets: [{
                                data: [85, 12, 3],
                                backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            }

            initSatisfactionCharts() {
                // Satisfaction levels chart
                const satisfactionCtx = document.getElementById('satisfaction-levels-chart')?.getContext('2d');
                if (satisfactionCtx) {
                    new Chart(satisfactionCtx, {
                        type: 'pie',
                        data: {
                            labels: ['Çok Memnun', 'Memnun', 'Orta', 'Memnun Değil'],
                            datasets: [{
                                data: [45, 35, 15, 5],
                                backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            }

            initPolicyCharts() {
                // Policy performance chart
                const policyPerfCtx = document.getElementById('policy-performance-chart')?.getContext('2d');
                if (policyPerfCtx) {
                    new Chart(policyPerfCtx, {
                        type: 'bar',
                        data: {
                            labels: ['Trafik', 'Kasko', 'Konut', 'DASK', 'Sağlık'],
                            datasets: [{
                                label: 'Poliçe Sayısı',
                                data: [120, 85, 45, 30, 25],
                                backgroundColor: '#3b82f6'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            }

            initRepresentativeCharts() {
                // Representative comparison chart
                const repCtx = document.getElementById('rep-comparison-chart')?.getContext('2d');
                if (repCtx) {
                    new Chart(repCtx, {
                        type: 'radar',
                        data: {
                            labels: ['Satış', 'Müşteri Memnuniyeti', 'Görev Tamamlama', 'Yenileme', 'Yeni Müşteri'],
                            datasets: [{
                                label: 'Ahmet Kaya',
                                data: [90, 85, 95, 88, 82],
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59, 130, 246, 0.2)'
                            }, {
                                label: 'Ayşe Demir',
                                data: [85, 92, 88, 90, 87],
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.2)'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            }

            initProfitabilityCharts() {
                // Profitability by type chart
                const profitCtx = document.getElementById('profitability-by-type-chart')?.getContext('2d');
                if (profitCtx) {
                    new Chart(profitCtx, {
                        type: 'bar',
                        data: {
                            labels: ['Trafik', 'Kasko', 'Konut', 'DASK', 'Sağlık'],
                            datasets: [{
                                label: 'Kar Marjı (%)',
                                data: [12, 18, 15, 20, 25],
                                backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444']
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                }
            }

            initRealTimeUpdates() {
                // Update dashboard every 30 seconds
                setInterval(() => {
                    if (this.currentTab === 'dashboard') {
                        this.updateRealTimeData();
                    }
                }, 30000);

                // Notification permission for real-time alerts
                if ('Notification' in window && Notification.permission === 'default') {
                    Notification.requestPermission().then(permission => {
                        if (permission === 'granted') {
                            this.showNotification('Gerçek zamanlı bildirimler etkinleştirildi', 'success');
                        }
                    });
                }
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

                // Show notification for important updates
                if ('Notification' in window && Notification.permission === 'granted') {
                    if (Math.random() < 0.1) { // 10% chance of showing notification
                        new Notification('CRM Raporları', {
                            body: 'Yeni veriler güncellendi',
                            icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" fill="%233b82f6"/><text x="16" y="24" font-family="Arial" font-size="20" text-anchor="middle" fill="white">📊</text></svg>',
                            badge: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" fill="%233b82f6"/><text x="16" y="24" font-family="Arial" font-size="20" text-anchor="middle" fill="white">📊</text></svg>'
                        });
                    }
                }
            }

            showNotification(message, type = 'info') {
                // Create notification element
                const notification = document.createElement('div');
                notification.className = `notification notification-${type}`;
                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    padding: 1rem;
                    border-radius: 0.5rem;
                    color: white;
                    z-index: 10000;
                    max-width: 300px;
                    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
                    animation: slideIn 0.3s ease-out;
                `;

                // Set background color based on type
                const colors = {
                    success: '#10b981',
                    error: '#ef4444',
                    warning: '#f59e0b',
                    info: '#3b82f6'
                };
                notification.style.backgroundColor = colors[type] || colors.info;

                notification.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span>${type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️'}</span>
                        <span>${message}</span>
                        <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; margin-left: auto;">×</button>
                    </div>
                `;

                document.body.appendChild(notification);

                // Auto remove after 5 seconds
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 5000);
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
            
            // Register service worker for PWA
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('data:text/javascript,const CACHE_NAME="crm-reports-v1";const urlsToCache=["/"];self.addEventListener("install",function(event){event.waitUntil(caches.open(CACHE_NAME).then(function(cache){return cache.addAll(urlsToCache);}))});self.addEventListener("fetch",function(event){event.respondWith(caches.match(event.request).then(function(response){if(response){return response;}return fetch(event.request);}))});')
                    .then(function(registration) {
                        console.log('Service Worker registered successfully:', registration);
                    })
                    .catch(function(error) {
                        console.log('Service Worker registration failed:', error);
                    });
            }
            
            // Add install prompt for PWA
            let deferredPrompt;
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                
                // Show install button
                const installButton = document.createElement('button');
                installButton.textContent = '📱 Uygulamayı Yükle';
                installButton.className = 'btn btn-primary';
                installButton.style.position = 'fixed';
                installButton.style.bottom = '20px';
                installButton.style.right = '20px';
                installButton.style.zIndex = '1000';
                
                installButton.addEventListener('click', () => {
                    installButton.style.display = 'none';
                    deferredPrompt.prompt();
                    deferredPrompt.userChoice.then((choiceResult) => {
                        if (choiceResult.outcome === 'accepted') {
                            console.log('User accepted the install prompt');
                        }
                        deferredPrompt = null;
                    });
                });
                
                document.body.appendChild(installButton);
            });
        });
    </script>
</body>
</html>