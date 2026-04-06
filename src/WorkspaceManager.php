<?php

class WorkspaceManager
{
    private \PDO $db;

    public function __construct(Database $database)
    {
        $this->db = $database->getPdo();
    }

    public function getAllWorkspaces(): array
    {
        $stmt = $this->db->query("SELECT * FROM workspaces ORDER BY parent_id ASC, sort_order ASC, name ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getTree(): array
    {
        $all = $this->getAllWorkspaces();
        return $this->buildTree($all);
    }

    private function buildTree(array $items, ?int $parentId = null): array
    {
        $result = [];
        foreach ($items as $item) {
            $itemParent = $item['parent_id'] === null ? null : (int)$item['parent_id'];
            if ($itemParent === $parentId) {
                $item['children'] = $this->buildTree($items, (int)$item['id']);
                $result[] = $item;
            }
        }
        return $result;
    }

    public function getWorkspace(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM workspaces WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createWorkspace(array $data): array
    {
        if (empty($data['name'])) {
            return ['success' => false, 'message' => 'El nombre es obligatorio'];
        }

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int)$data['parent_id'] : null;

        // Prevent circular references
        if ($parentId !== null && !$this->workspaceExists($parentId)) {
            return ['success' => false, 'message' => 'El workspace padre no existe'];
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO workspaces (name, description, parent_id, icon, color, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                trim($data['name']),
                $data['description'] ?? null,
                $parentId,
                $data['icon'] ?? '📁',
                $data['color'] ?? '#38bdf8',
                $data['sort_order'] ?? 0,
            ]);
            return ['success' => true, 'id' => (int)$this->db->lastInsertId(), 'message' => 'Workspace creado'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function updateWorkspace(int $id, array $data): array
    {
        if (empty($data['name'])) {
            return ['success' => false, 'message' => 'El nombre es obligatorio'];
        }

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int)$data['parent_id'] : null;

        // Prevent moving a workspace under itself or its descendants
        if ($parentId !== null) {
            if ($parentId === $id) {
                return ['success' => false, 'message' => 'Un workspace no puede ser su propio padre'];
            }
            if ($this->isDescendant($id, $parentId)) {
                return ['success' => false, 'message' => 'No se puede mover un workspace dentro de uno de sus descendientes'];
            }
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE workspaces
                SET name = ?, description = ?, parent_id = ?, icon = ?, color = ?, sort_order = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([
                trim($data['name']),
                $data['description'] ?? null,
                $parentId,
                $data['icon'] ?? '📁',
                $data['color'] ?? '#38bdf8',
                $data['sort_order'] ?? 0,
                $id,
            ]);
            return ['success' => true, 'message' => 'Workspace actualizado'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function deleteWorkspace(int $id): array
    {
        try {
            // Move child workspaces to parent of deleted workspace
            $ws = $this->getWorkspace($id);
            if (!$ws) {
                return ['success' => false, 'message' => 'Workspace no encontrado'];
            }

            // Reparent children to the deleted workspace's parent
            $stmt = $this->db->prepare("UPDATE workspaces SET parent_id = ? WHERE parent_id = ?");
            $stmt->execute([$ws['parent_id'], $id]);

            // Unassign apps from this workspace
            $stmt = $this->db->prepare("UPDATE apps SET workspace_id = NULL WHERE workspace_id = ?");
            $stmt->execute([$id]);

            $stmt = $this->db->prepare("DELETE FROM workspaces WHERE id = ?");
            $stmt->execute([$id]);

            return ['success' => true, 'message' => 'Workspace eliminado'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function moveApp(int $appId, ?int $workspaceId): array
    {
        try {
            if ($workspaceId !== null && !$this->workspaceExists($workspaceId)) {
                return ['success' => false, 'message' => 'Workspace no encontrado'];
            }
            $stmt = $this->db->prepare("UPDATE apps SET workspace_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$workspaceId, $appId]);
            return ['success' => true, 'message' => 'Aplicación movida'];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function workspaceExists(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM workspaces WHERE id = ?");
        $stmt->execute([$id]);
        return (bool)$stmt->fetch();
    }

    private function isDescendant(int $ancestorId, int $nodeId): bool
    {
        // Check if $nodeId is a descendant of $ancestorId
        $stmt = $this->db->prepare("SELECT parent_id FROM workspaces WHERE id = ?");
        $current = $nodeId;
        $visited = [];
        while ($current !== null) {
            if (in_array($current, $visited)) break; // cycle guard
            $visited[] = $current;
            $stmt->execute([$current]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) break;
            $current = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            if ($current === $ancestorId) return true;
        }
        return false;
    }
}
