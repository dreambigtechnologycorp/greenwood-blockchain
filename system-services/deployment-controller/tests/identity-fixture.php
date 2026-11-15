<?php
declare(strict_types=1);
\Greenwood\DeploymentController\Auth\DeploymentIdentity::configure([
 'chain_id'=>getenv('GWD_CONTROL_PLANE_TEST_CHAIN')?:'greenwood-physical-v1','genesis_hash'=>str_repeat('a',64),
 'registry_contract_id'=>'37ee42792a1fbb2aadc2f4a9fc5ad37a6b5528669e8aa8a90be75f16ba5df2f6',
 'registry_wasm_hash'=>'37ee42792a1fbb2aadc2f4a9fc5ad37a6b5528669e8aa8a90be75f16ba5df2f6']);
