# DBoard user frontend

This is the Vue user portal source for the JC site shown in the DBoard dashboard screenshot. It was imported from [PangHu-Code/EZ-Theme](https://github.com/PangHu-Code/EZ-Theme) at commit `6e24d2f` and is maintained here as part of DBoard. Keep the upstream attribution and license notices when changing it.

The production API and gateway settings live in the untracked `public/runtime-config.js`. Do not commit that file. Build on HK33-2 with `npm install` and `npm run build`; deploy the `dist/` files to the JC static site while preserving the production runtime config and site images.
