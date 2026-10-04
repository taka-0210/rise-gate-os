# Company OS Login｜Brand Visual Prototype v001

ブランドサイトとログイン体験のVisual DirectionをHuman Reviewするための、認証非接続Prototypeです。

## 起動

どのディレクトリからでも実行できる、次の絶対パスを使用します。

    powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\rise-gate-os\prototypes\company-os-login-brand-v001\Open-CompanyOSLoginBrandPrototype.ps1

相対パスを使う場合は、先にRepository直下へ移動します。

    cd C:\xampp\htdocs\rise-gate-os
    powershell -ExecutionPolicy Bypass -File .\prototypes\company-os-login-brand-v001\Open-CompanyOSLoginBrandPrototype.ps1

Review画面上部で「Desktop 1440×900」と「Mobile 390×844」を切り替えられます。

## Review対象

- 白基調のブランドサイトからCompany OSへの視覚的な連続性
- 左側のブランドコピーとログイン操作の情報Hierarchy
- 右側の既存Company OS円形VisualとのComposition
- Mobileで円形Visualを背景化した際のフォーム可読性

## Boundary

- 既存の public/images/company-os-brand-symbol.svg を正本候補として再利用しています。
- 認証・送信・Password resetは接続していません。
- 正式なWelcome / Login Blade、Route、Permission、Data、DBは変更していません。
- Prototype採用後の正式Product統合は、別のHuman Decision対象です。
