# install_artifacts/

`install.sh` 用到的 Nginx / Supervisor / crontab 模板。

- `*.template` 文件是模板，含 `{{INSTALL_DIR}}` `{{DOMAIN}}` `{{PHP_BIN}}` `{{PHP_FPM_SOCK}}` `{{WEB_USER}}` 占位符。
- `install.sh` 运行时会用实际值替换占位符，把渲染结果写入 `output/` 目录。
- `output/` 不入库（见 `.gitignore`）。

Templates used by `install.sh`. Placeholders are replaced at install time
and rendered files go into `output/` (which is gitignored).
