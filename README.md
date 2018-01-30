rips-cli
========

A command line interface for RIPS v2.

[![Demo](https://asciinema.org/a/160101.png)](https://asciinema.org/a/160101?autoplay=1)

# Requirements
To use `rips-cli` you need `php-cli` as well as the `php-zip` extension.
It is recommended to use the PHAR build of `rips-cli` from https://kb.ripstech.com/display/DOC/RIPS+CLI. If you do not plan to use the PHAR you have to download the dependencies with Composer first. Also, you have to execute `bin/console` instead of `rips-cli`.

 * composer install
 * php bin/console

# Usage
## Configuration
`rips-cli` looks for the configuration file `~/.rips.yml` and uses it if it is available. You can create the file with `rips-cli` itself. For example, by calling `rips-cli rips:login` you store credentials in the configuration to avoid having to enter them on every command. Be aware that the password is stored in clear text.

## Environment
You can also use environment variables to set certain properties.

| Name          | Description                    |
|---------------|--------------------------------|
| RIPS_BASE_URI | Set API address                |
| RIPS_USERNAME | Set API username               |
| RIPS_PASSWORD | Set API password               |
| RIPS_CONFIG   | Set path to configuration file |

## Commands
### General
#### Help
Call `rips-cli` without any parameters to see a list of all commands. Use `--help` or `-h` in combination with a command to see all available parameters.

#### Errors
In case an API request fails you will see an error message. A list with common errors and their solutions is available at https://kb.ripstech.com/display/DOC/Troubleshooting.

#### Filter
Many commands allow you to use the filter system of the API. It is accessible through query parameters (`--parameter` or `-p`). More information are available at https://kb.ripstech.com/display/DOC/Filter.

#### Input/Output
If required parameters are not specified there are `stdin` fall-backs in place to get values. The fall-backs can be suppressed by appending `--no-interaction` or `-n` to the command. If you do not want to see output use `--quiet` or `-q`. If you want to see a lot of output use `--verbose` or `-v`.

### rips:application:create
This command creates a new application.

#### Examples
 * rips-cli rips:application:create -v
 * rips-cli rips:application:create -N DVWA

### rips:scan:start
This command starts a scan. It can either upload an existing archive, upload a directory, use an existing upload, or start a scan with a local path.

The command has a `threshold` parameter. If the parameter is specified once or multiple times, the script waits until the scan is finished and compares the number of unreviewed issues to the thresholds. If the number of issues exceeds the thresholds, the program exits with the status code `2`.
A threshold consists of a category (`low`, `medium`, `high`, `critical`, `sum`), a colon, and a number. A threshold that consists only of a number is treated like `sum`.

#### Examples
 * rips-cli rips:scan:start
 * rips-cli rips:scan:start -a 1 -p /var/www --threshold 0 -v
 * rips-cli rips:scan:start -a 1 -p dvwa -N 'DVWA 1.8' --local -v
 * rips-cli rips:scan:start -a 1 -U 3 --keep-upload -t 14 -t high:5 -t critical:0
 * rips-cli rips:scan:start -a 1 -Q 4 -p /var/www -E 'config\\.php$' -E 'test\\/\\.git'

### rips:scan:export
This command exports a scan to PDF, CSV, or Jira CSV.

#### Examples
 * rips-cli rips:scan:export
 * rips-cli rips:scan:export -a 1 -s 10 -t pdf -f report
 * rips-cli rips:scan:export -a 1 -s 10 -t jiracsv -p 'equal[origin]=1' -n

### rips:list:setup
This command allows you to modify the shown columns of a table.

#### Examples
 * rips-cli rips:list:setup
 * rips-cli rips:list:setup -t applications
 * rips-cli rips:list:setup -t issues --remove

### rips:list
This command lists entries of a table.

#### Examples
 * rips-cli rips:list
 * rips-cli rips:list -t applications -p 'limit=5' -p 'orderBy[currentScan]=desc'
 * rips-cli rips:list -t scans -p 'equal[percent]=100' -p 'greaterThan[loc]=5000' 1
 * rips-cli rips:list -t scans -n
 * rips-cli rips:list -t issues --max-chars 160 1 10

### rips:delete
This command deletes entries of a table.

By default this command only deletes single entries. Enable `--list` or `-L` to delete multiple entries at once.

#### Examples
 * rips-cli rips:delete
 * rips-cli rips:delete -t scans 1 5
 * rips-cli rips:delete -t applications -L -p 'limit=5' -p 'orderBy[currentScan]=desc'

### rips:issues:list
This command allows you to search through all issues.

The command first searches for all available scans and then searches for issues in the scans. Both processes can be filtered through own query parameters.
If no parameters are specified all issues are shown. Depending on the amount of scans this might take a while.

#### Examples
 * rips-cli rips:issues:list -v
 * rips-cli rips:issues:list -P limit=15 -P 'orderBy[application]=asc' -P 'orderBy[id]=asc' -p 'greaterThan[typeSeverity]=60'
 * rips-cli rips:issues:list -v --issue-parameter 'notNull[cve]'

### rips:issues:review
This command allows you to mass review issues.

#### Examples
 * rips-cli rips:issues:review
 * rips-cli rips:issues:review -t 8 -a 1 -s 5 -p 'equal[origin]=2' -p 'greaterThan[depth]=5'

### rips:login
This command validates and stores the credentials in the configuration file.

### rips:logout
This command removes the credentials from the configuration file.