rips-cli
========

A command line interface for RIPS v3.

<a href="https://asciinema.org/a/160101?autoplay=1"><img src="https://asciinema.org/a/160101.png" width="600" /></a>

# Requirements
To use `rips-cli` you need `php-cli` as well as the `php-zip` extension.
It is recommended to use the [PHAR build](https://kb.ripstech.com/display/DOC/RIPS+CLI#RIPSCLI-PHAR) of `rips-cli`. If you do not plan to use the PHAR you have to download the dependencies with Composer first. Also, you have to execute `bin/console` instead of `rips-cli`.

 * composer install
 * php bin/console

# Usage
## Configuration
`rips-cli` looks for the configuration file `~/.rips3.yml` and uses it if it is available. You can create the file with `rips-cli` itself. For example, by calling `rips-cli rips:login` you store credentials in the configuration to avoid having to enter them on every command. Be aware that the password is stored in clear text.

## Environment
You can also use environment variables to set certain properties.

| Name          | Description                    |
|---------------|--------------------------------|
| RIPS_BASE_URI | Set API address                |
| RIPS_EMAIL    | Set API e-mail                 |
| RIPS_PASSWORD | Set API password               |
| RIPS_CONFIG   | Set path to configuration file |

## Help
Call `rips-cli` without any parameters to see a list of all commands. Use `--help` or `-h` in combination with a command to see all available parameters.
For more details please refer to the [documentation](https://kb.ripstech.com/display/DOC/RIPS+CLI).
