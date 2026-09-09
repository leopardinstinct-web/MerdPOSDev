<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/timesheet_portal/includes/dashboard_layout_write.php';
function audit_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
class LayoutPDO extends PDO {
    public array $rows = [['original',0,0,4,3]], $saved = [], $events = [], $log = [];
    public bool $tx = false, $failAudit = false;
    public function __construct() {}
    public function beginTransaction(): bool { $this->saved=$this->rows; $this->tx=true; $this->log[]='begin'; return true; }
    public function inTransaction(): bool { return $this->tx; }
    public function commit(): bool { $this->log[]='commit'; $this->tx=false; return true; }
    public function rollBack(): bool { $this->rows=$this->saved; $this->log[]='rollback'; $this->tx=false; return true; }
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new LayoutStatement($this,$query); }
}
class LayoutStatement extends PDOStatement {
    public function __construct(private LayoutPDO $db, private string $sql) {}
    public function execute(?array $params=null): bool {
        audit_check($this->db->inTransaction(),'Write occurred outside transaction');
        audit_check($params[0]===7 && $params[1]===10,'Client/role scope lost');
        if (str_starts_with($this->sql,'DELETE')) $this->db->rows=[];
        else $this->db->rows[]=array_slice($params,2);
        return true;
    }
}
function beta_admin_audit(PDO $pdo,array $actor,string $action,string $entity,?string $id,array $details): void {
    audit_check($pdo->inTransaction(),'Audit occurred outside transaction');
    audit_check($actor['client_id']===7 && $id==='10' && $entity==='dashboard_role_layout','Audit scope lost');
    audit_check(!isset($details['password']) && $details['widget_count']===count($details['widget_keys']),'Unsafe audit details');
    if ($pdo->failAudit) throw new RuntimeException('synthetic audit failure');
    $pdo->log[]='audit'; $pdo->events[]=compact('actor','action','details');
}
$role=['id'=>10,'role_key'=>'SUPER']; $layout=[['working_now',0,1,6,3]];
foreach ([['client_id'=>7,'id'=>44],['client_id'=>7,'platform_identity_id'=>2]] as $actor) {
    foreach ([false,true] as $reset) {
        $pdo=new LayoutPDO;
        dashboard_write_layout($pdo,$actor,$role,$layout,$reset);
        audit_check($pdo->rows===($reset?[]:$layout),'Persisted layout mismatch');
        audit_check($pdo->log===['begin','audit','commit'],'Audit must precede commit');
        audit_check($pdo->events[0]['actor']===$actor,'Actor identity not preserved');
        audit_check($pdo->events[0]['action']===($reset?'dashboard.layout.reset':'dashboard.layout.save'),'Audit action mismatch');
        $pdo=new LayoutPDO; $before=$pdo->rows; $pdo->failAudit=true;
        try { dashboard_write_layout($pdo,$actor,$role,$layout,$reset); throw new LogicException('Expected audit failure'); }
        catch (RuntimeException $e) { audit_check($e->getMessage()==='synthetic audit failure','Unexpected failure'); }
        audit_check($pdo->rows===$before && $pdo->log===['begin','rollback'],'Audit failure did not restore layout');
    }
}
$api=file_get_contents(dirname(__DIR__,2).'/timesheet_portal/api/dashboard_layout.php');
audit_check(str_contains($api,'dashboard_write_layout($pdo, $user, $role, [], true)'),'Reset is not wired');
audit_check(str_contains($api,'dashboard_write_layout($pdo, $user, $role, $clean)'),'Save is not wired');
echo "Dashboard audit checks passed: save/reset, employee/platform actors, scope, atomic audit failure rollback.\n";
