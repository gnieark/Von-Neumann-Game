#!/usr/bin/env bash
# Run on the development VM, while ALL production writers remain stopped.
set -euo pipefail
umask 077
if [[ $# -lt 2 || "$2" != '--maintenance-confirmed' ]]; then
    echo "Usage: bash $0 USER@PROD_VM --maintenance-confirmed [ABSOLUTE_PROD_UNIVERSE_PATH]" >&2
    echo "Stop production writers and wait for active requests first; this script does not stop them." >&2
    exit 2
fi
cd "$(dirname "$0")/../.."
prod_ssh="$1"
prod_universe="${3:-/var/www/neumann-probe.tinad.fr/data/universe}"
copy_directory="$(mktemp -d "$PWD/var/lot6-copy-XXXXXXXX")"
client_file="$(mktemp)"
trap 'rm -f -- "$client_file"' EXIT
php -r '
$c=json_decode(file_get_contents("var/database-prod.json"),true,512,JSON_THROW_ON_ERROR);
if (($c["driver"]??null)!=="mysql") {throw new RuntimeException("Expected MySQL configuration");}
$s="[client]\n";
foreach (["host"=>"host","port"=>"port","username"=>"user","password"=>"password"] as $from=>$to) {
    $v=str_replace(["\\", "\"", "\n", "\r"], ["\\\\", "\\\"", "\\n", "\\r"], (string)($c[$from]??""));
    $s.=$to."=\"".$v."\"\n";
}
if (file_put_contents($argv[1],$s)!==strlen($s)) {throw new RuntimeException("Cannot write client configuration");}
' "$client_file"
database_name="$(php -r '$c=json_decode(file_get_contents("var/database-prod.json"),true,512,JSON_THROW_ON_ERROR);echo $c["database"];')"
mkdir "$copy_directory/universe"
# This internal MariaDB server does not offer TLS. SSH authenticates rsync only.
mysqldump --defaults-extra-file="$client_file" --skip-ssl --single-transaction --quick \
    --skip-lock-tables --hex-blob --no-tablespaces "$database_name" > "$copy_directory/database.sql"
rsync -a --checksum --info=progress2 --exclude='*.lock' \
    "$prod_ssh:$prod_universe/" "$copy_directory/universe/"
test -d "$copy_directory/universe/sectors"
sha256sum "$copy_directory/database.sql" > "$copy_directory/database.sql.sha256"
printf 'Copy completed: %s\nProduction must have remained quiescent throughout both commands.\n' "$copy_directory"
