<?php
declare(strict_types=1);
// Explicit CLI-only synthetic draft. It never invokes workflow or messaging services.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??'')!=='--rochdale-draft'||$argc!==2){fwrite(STDERR,"Use --rochdale-draft to create the labelled test draft.\n");exit(2);}
ini_set('display_errors','0');
try {
    [, $db]=require dirname(__DIR__).'/src/bootstrap.php';
    $pdo=$db->pdo;$id='82244d6e-ae1b-48ac-a240-0fb1e433f9cf';
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT id,site_block,status FROM forms WHERE id=?');$stmt->execute([$id]);$existing=$stmt->fetch(PDO::FETCH_ASSOC);
    if($existing){if($existing['site_block']!=='Rochdale Road'||$existing['status']!=='draft')throw new RuntimeException();}
    else {
        $template=$pdo->query('SELECT id FROM form_templates WHERE active=1 ORDER BY name,id LIMIT 1')->fetchColumn();if(!$template)throw new RuntimeException();
        $data=json_encode(['siteProject'=>'Rochdale Road','companyName'=>'McGoff Construction','description'=>'SUITE TEST ONLY — Synthetic draft. Not an issued permit; no work is authorised.']);
        $metadata=json_encode(['suite_test_fixture'=>true,'fixture_version'=>1]);
        $stmt=$pdo->prepare("INSERT INTO forms(id,ref_number,template_id,form_data,site_block,status,metadata,requires_approval) VALUES(?,?,?,?,?,'draft',?,1)");
        $stmt->execute([$id,'TEST-SUITE-ROCHDALE',$template,$data,'Rochdale Road',$metadata]);
    }
    $pdo->commit();
    echo json_encode(['ok'=>true,'site'=>'Rochdale Road','status'=>'draft','created'=>!$existing,'emails_sent'=>0,'work_authorised'=>false])."\n";
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Test draft could not be prepared. No permit workflow was invoked.\n");exit(1);}
