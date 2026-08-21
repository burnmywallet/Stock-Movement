<?php
// ================================================================
// نظام إدارة المخازن والمخزون المتقدم
// الملف: backend/middleware/PermissionMiddleware.php
// الوصف: ميدل وير التحقق من الصلاحيات
// الإصدار: 2.0 Production Ready
// التاريخ: 2026-08-20
// ================================================================

namespace Middleware;

use Core\Auth;
use Core\Audit;

class PermissionMiddleware
{
    /**
     * @var Auth $auth - نظام المصادقة
     */
    private $auth;
    
    /**
     * @var array $requiredPermissions - الصلاحيات المطلوبة
     */
    private $requiredPermissions = [];
    
    /**
     * @var string $mode - وضع التحقق (all, any)
     */
    private $mode = 'all';

    public function __construct()
    {
        $this->auth = new Auth();
    }

    /**
     * معالجة الطلب
     */
    public function handle(array $params = []): bool
    {
        $userId = $_REQUEST['user_id'] ?? null;
        
        if (!$userId) {
            $this->forbidden('يجب تسجيل الدخول أولاً');
            return false;
        }

        // الحصول على الصلاحيات المطلوبة من المعلمات
        $permissions = $params['permissions'] ?? $params[0] ?? null;
        
        if (empty($permissions)) {
            // لا توجد صلاحيات مطلوبة
            return true;
        }

        // تحويل إلى مصفوفة
        if (is_string($permissions)) {
            $permissions = [$permissions];
        }

        // التحقق من الصلاحيات
        $hasPermission = $this->checkPermissions($userId, $permissions);
        
        if (!$hasPermission) {
            $this->logPermissionDenied($userId, $permissions);
            $this->forbidden('ليس لديك صلاحية للقيام بهذه العملية');
            return false;
        }

        return true;
    }

    /**
     * التحقق من الصلاحيات
     */
    private function checkPermissions(int $userId, array $permissions): bool
    {
        $mode = $this->mode;
        
        foreach ($permissions as $permission) {
            $has = $this->auth->hasPermission($userId, $permission);
            
            if ($mode === 'all' && !$has) {
                return false;
            }
            
            if ($mode === 'any' && $has) {
                return true;
            }
        }
        
        return $mode === 'all';
    }

    /**
     * تسجيل رفض الصلاحية
     */
    private function logPermissionDenied(int $userId, array $permissions): void
    {
        $path = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        
        Audit::log(
            $userId,
            'PERMISSION_DENIED',
            'rbac',
            'محاولة وصول بدون صلاحية',
            [
                'path' => $path,
                'method' => $method,
                'required_permissions' => $permissions,
                'mode' => $this->mode,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
            ]
        );
    }

    /**
     * إرسال استجابة ممنوعة
     */
    private function forbidden(string $message): void
    {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => 'FORBIDDEN',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * تعيين وضع التحقق (all/any)
     */
    public function setMode(string $mode): self
    {
        $this->mode = $mode === 'any' ? 'any' : 'all';
        return $this;
    }

    /**
     * تعيين الصلاحيات المطلوبة
     */
    public function setPermissions(array $permissions): self
    {
        $this->requiredPermissions = $permissions;
        return $this;
    }

    /**
     * إضافة صلاحية مطلوبة
     */
    public function addPermission(string $permission): self
    {
        $this->requiredPermissions[] = $permission;
        return $this;
    }
}
