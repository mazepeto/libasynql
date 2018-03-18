<?php

/*
 * libasynql_v3
 *
 * Copyright (C) 2018 SOFe
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

use poggit\libasynql\generic\GenericStatementFileParser;
use poggit\libasynql\GenericStatement;

require_once Phar::running() . "/cli-autoload.php";

if(!isset($argv[4])){
	echo "[!] Usage: php " . escapeshellarg($argv[0]) . " def <src> <fqn> <SQL file>\n";
	echo "[*] Generates a query name constants interface file from the SQL file\n";
	exit(2);
}

[, , $srcDir, $fqn] = $argv;

if(!is_dir($srcDir)){
	echo "[!] $srcDir: No such directory\n";
	exit(2);
}

if(!preg_match('/^[a-z_]\w*(\\\\[a-z_]\w*)*$/i', $fqn)){
	echo "[!] $fqn: Invalid FQN\n";
	exit(2);
}
$fqnPieces = explode("\\", $fqn);

$prefix = "";

$i = 4;
if(strpos($argv[$i], "--prefix") === 0){
	$prefix = $argv[$i + 1];
	$i += 2;
}
$sqlFiles = [];
for($iMax = count($argv); $i < $iMax; ++$i){
	$sqlFile = $argv[$i];
	if(!is_file($sqlFile)){
		echo "[!] $sqlFile: No such file\n";
		exit(2);
	}
	$sqlFiles[] = $sqlFile;
}

/** @var GenericStatement[][] $results */
$results = [];
foreach($sqlFiles as $sqlFile){
	$fh = fopen($sqlFile, "rb");
	$parser = new GenericStatementFileParser($fh);
	$parser->parse();

	foreach($parser->getResults() as $stmt){
		$results[$stmt->getName()][$sqlFile] = $stmt;
	}
}

$itfFile = realpath($srcDir) . "/" . str_replace("\\", "/", $fqn) . ".php";
@mkdir(dirname($itfFile), 0777, true);

$fh = fopen($itfFile, "wb");
fwrite($fh, '<?php' . PHP_EOL);
fwrite($fh, '' . PHP_EOL);
fwrite($fh, '/*' . PHP_EOL);
fwrite($fh, ' * Auto-generated by libasynql-def' . PHP_EOL);
fwrite($fh, ' * Created from ' . implode(", ", array_map("basename", $sqlFiles)) . PHP_EOL);
fwrite($fh, ' */' . PHP_EOL);
fwrite($fh, '' . PHP_EOL);
fwrite($fh, 'namespace ' . implode("\\", array_slice($fqnPieces, 0, -1)) . ';' . PHP_EOL);
fwrite($fh, '' . PHP_EOL);
fwrite($fh, 'interface ' . array_slice($fqnPieces, -1)[0] . '{' . PHP_EOL);
$constLog = [];
foreach($results as $queryName => $stmts){
	$const = preg_replace('/[^A-Z0-9]+/i', "_", strtoupper($queryName));
	if(ctype_digit($queryName{0})){
		$const = "_" . $const;
	}
	if(isset($constLog[$const])){
		$i = 2;
		while(isset($constLog[$const . "_" . $i])){
			++$i;
		}
		echo "Warning: Similar query names {$constLog[$const]} and {$queryName}, generating numerically-assigned constant string {$prefix}{$const}_{$i}\n";
		$const .= "_" . $i;
	}
	$constLog[$const] = $queryName;
	$descLines = ["<code>" . $queryName . "</code>", "", "Defined in " . implode(", ", array_map("basename", array_keys($stmts)))];
	/** @var GenericStatement $stmt0 */
	$stmt0 = array_values($stmts)[0];
	$file0 = array_keys($stmts)[0];
	$variables = $vars0 = $stmt0->getVariables();
	$varFiles = [];
	foreach($variables as $varName => $variable){
		$varFiles[$varName] = [$file0 => $variable->isOptional()];
	}
	foreach($stmts as $file => $stmt){
		if($file === $file0){
			continue;
		}
		$vars = $stmt->getVariables();
		foreach($vars0 as $varName => $var0){
			if(isset($vars[$varName])){
				/** @noinspection NotOptimalIfConditionsInspection */
				if(!$var0->equals($vars[$varName], $diff) && $diff !== "type" && $diff !== "defaultValue"){
					echo "[!] Conflict: $queryName :$varName have different declarations ($diff) in $file0 and $file\n";
					exit(1);
				}
				if($var0->isOptional() !== $vars[$varName]->isOptional()){
					echo "[*] Notice: :$varName is " . ($var0->isOptional() ? "optional" : "required") . " for $queryName in $file0 but " . ($var0->isOptional() ? "required" : "optional") . " in $file\n";
				}
				$varFiles[$varName][$file] = $vars[$varName]->isOptional();
			}else{
				echo "[*] Notice: :$varName is defined for $queryName in $file0 but not in $file\n";
			}
		}
		foreach($vars as $varName => $var){
			if(!isset($vars0[$varName])){
				$opt = $var->isOptional() ? "optional" : "required";
				echo "[*] Notice: :$varName is $opt for $queryName in $file but not defined in $file0\n";
				$varFiles[$varName][$file] = $var->isOptional();
			}
		}
	}
	if(!empty($varFiles)){
		$descLines[] = "";
		$descLines[] = "Variables:";
		foreach($varFiles as $varName => $files){
			$var = $vars0[$varName];
			$desc = "- <code>:$varName</code> {$var->getType()}";
			if($var->isList()){
				$desc .= $var->canBeEmpty() ? " list" : " non-empty list";
			}
			$required = [];
			$optional = [];
			foreach($files as $file => $isOptional){
				if($isOptional){
					$optional[] = $file;
				}else{
					$required[] = $file;
				}
			}
			if(!empty($required)){
				$desc .= ", required in " . implode(", ", array_map("basename", $required));
			}
			if(!empty($optional)){
				$desc .= ", optional in " . implode(", ", array_map("basename", $optional));
			}
			$descLines[] = $desc;
		}
	}
	fwrite($fh, "\t/**" . PHP_EOL);
	foreach($descLines as $line){
		fwrite($fh, (strlen($line) > 0 ? "\t * $line" : "\t *") . PHP_EOL);
	}
	fwrite($fh, "\t */" . PHP_EOL);
	fwrite($fh, "\tpublic const {$prefix}{$const} = " . json_encode($queryName) . ';' . PHP_EOL);
}

fwrite($fh, '}' . PHP_EOL);
fclose($fh);
exit(0);