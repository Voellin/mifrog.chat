# bin/

Mifrog 部署所需的二进制文件。

## lark-cli

飞书命令行工具（lark-cli），用于多用户飞书 API 调用。

- **架构**：Linux x86-64（静态链接，约 15 MB）
- **来源**：飞书开放平台官方分发
- **用法**：`install.sh` 会自动复制到 `/usr/local/bin/lark-cli`，普通用户无需手动操作

如果你的服务器是 ARM 架构（M1 Mac / 鲲鹏 / 树莓派等），请到飞书开放平台
下载对应架构的二进制覆盖此文件，或修改 `install.sh` 跳过复制步骤。

## 升级 lark-cli

1. 从飞书开放平台下载新版二进制
2. 替换 `bin/lark-cli`
3. `git commit -m "chore(bin): bump lark-cli to vX.Y.Z"`
