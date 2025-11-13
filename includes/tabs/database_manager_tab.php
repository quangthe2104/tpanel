<?php
try {
    $dbManager = new DatabaseManager(
        $website['db_host'],
        $website['db_name'],
        $website['db_user'],
        $website['db_password']
    );
    
    $security = Security::getInstance();
    $action = isset($_GET['action']) && in_array($_GET['action'], ['tables', 'table', 'query']) ? $_GET['action'] : 'tables';
    $tableName = $security->sanitizeString($_GET['table'] ?? '', 100);
    
    // Handle query execution (chỉ SELECT)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_query'])) {
        $security->checkCSRF();
        
        $query = trim($_POST['query'] ?? '');
        if (empty($query)) {
            $queryError = "Query không được để trống";
            $querySuccess = false;
        } elseif (stripos($query, 'SELECT') !== 0) {
            $queryError = "Chỉ cho phép thực thi SELECT queries để bảo mật";
            $querySuccess = false;
        } else {
            try {
                $result = $dbManager->executeQuery($query);
                $auth->logActivity($auth->getUserId(), $websiteId, 'database_query', 'SQL SELECT query executed');
                $queryResult = $result;
                $querySuccess = true;
            } catch (Exception $e) {
                $queryError = $e->getMessage();
                $querySuccess = false;
            }
        }
    }
    
    $tables = $dbManager->getTables();
    $dbSize = $dbManager->getDatabaseSize();
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
?>

<?php if (isset($dbError)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle"></i> Lỗi kết nối database: <?php echo escape($dbError); ?>
    </div>
<?php else: ?>
    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-database"></i> Database: <?php echo escape($website['db_name']); ?></h5>
                </div>
                <div class="card-body">
                    <p><strong>Kích thước:</strong> <?php echo number_format($dbSize, 2); ?> MB</p>
                    <p><strong>Host:</strong> <?php echo escape($website['db_host']); ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Danh sách Tables</h5>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <?php foreach ($tables as $table): ?>
                            <a href="<?php echo websiteUrl($websiteId, 'database') . '?action=table&table=' . urlencode($table); ?>" 
                               class="list-group-item list-group-item-action <?php echo $tableName == $table ? 'active' : ''; ?>">
                                <i class="bi bi-table"></i> <?php echo escape($table); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <?php if ($action == 'table' && $tableName): ?>
                <?php
                $tableStructure = $dbManager->getTableStructure($tableName);
                $tableCount = $dbManager->getTableCount($tableName);
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                $limit = 50;
                $offset = ($page - 1) * $limit;
                $tableData = $dbManager->getTableData($tableName, $limit, $offset);
                ?>
                
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Table: <?php echo escape($tableName); ?></h5>
                        <span class="badge bg-info"><?php echo $tableCount; ?> rows</span>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-tabs mb-3">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#structure">Cấu trúc</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#data">Dữ liệu</a>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="structure">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Field</th>
                                                <th>Type</th>
                                                <th>Null</th>
                                                <th>Key</th>
                                                <th>Default</th>
                                                <th>Extra</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($tableStructure as $field): ?>
                                            <tr>
                                                <td><?php echo escape($field['Field']); ?></td>
                                                <td><?php echo escape($field['Type']); ?></td>
                                                <td><?php echo escape($field['Null']); ?></td>
                                                <td><?php echo escape($field['Key']); ?></td>
                                                <td><?php echo escape($field['Default'] ?? 'NULL'); ?></td>
                                                <td><?php echo escape($field['Extra']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="data">
                                <?php if (empty($tableData)): ?>
                                    <p class="text-muted">Không có dữ liệu</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <?php foreach (array_keys($tableData[0]) as $column): ?>
                                                        <th><?php echo escape($column); ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($tableData as $row): ?>
                                                    <tr>
                                                        <?php foreach ($row as $value): ?>
                                                            <td><?php echo escape($value ?? 'NULL'); ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <?php if ($tableCount > $limit): ?>
                                        <nav>
                                            <ul class="pagination">
                                                <?php
                                                $totalPages = ceil($tableCount / $limit);
                                                for ($i = 1; $i <= $totalPages; $i++):
                                                ?>
                                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                                        <a class="page-link" href="<?php echo websiteUrl($websiteId, 'database') . '?action=table&table=' . urlencode($tableName) . '&page=' . $i; ?>">
                                                            <?php echo $i; ?>
                                                        </a>
                                                    </li>
                                                <?php endfor; ?>
                                            </ul>
                                        </nav>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <p class="text-muted">Chọn một table để xem chi tiết</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- SQL Query Executor -->
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-code-square"></i> SQL Query</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php $security = Security::getInstance(); echo $security->getCSRFField(); ?>
                <div class="mb-3">
                    <label class="form-label">SQL Query (Chỉ SELECT)</label>
                    <textarea name="query" class="form-control" rows="5" placeholder="SELECT * FROM table_name LIMIT 10;" required><?php echo isset($_POST['query']) ? escape($_POST['query']) : ''; ?></textarea>
                    <small class="text-muted">Chỉ cho phép SELECT queries để bảo mật</small>
                </div>
                <button type="submit" name="execute_query" class="btn btn-primary">
                    <i class="bi bi-play"></i> Thực thi
                </button>
            </form>
            
            <?php if (isset($querySuccess)): ?>
                <?php if ($querySuccess): ?>
                    <div class="alert alert-success mt-3">
                        <strong>Thành công!</strong> Query đã được thực thi.
                    </div>
                    <?php if (isset($queryResult) && is_array($queryResult)): ?>
                        <div class="table-responsive mt-3">
                            <table class="table table-sm table-hover">
                                <?php if (!empty($queryResult)): ?>
                                    <thead>
                                        <tr>
                                            <?php foreach (array_keys($queryResult[0]) as $column): ?>
                                                <th><?php echo escape($column); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($queryResult as $row): ?>
                                            <tr>
                                                <?php foreach ($row as $value): ?>
                                                    <td><?php echo escape($value ?? 'NULL'); ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                <?php endif; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-danger mt-3">
                        <strong>Lỗi:</strong> <?php echo escape($queryError); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
