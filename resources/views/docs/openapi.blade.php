<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VeggieMap API 文件</title>
    {{--
      Redoc 從 CDN 載入，而不是把它 npm install 進前端 bundle：這頁跟 SPA 是兩件事，
      沒有理由讓每個使用者都下載一份文件工具的 JS。代價是這頁需要外網——所以
      docs/openapi.yaml 本身仍然是可以直接讀的純文字，也可以匯進 Swagger UI／Postman，
      不依賴這一頁。
    --}}
    <style>body { margin: 0; }</style>
</head>
<body>
<redoc spec-url="{{ route('docs.openapi.spec') }}"></redoc>
<script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
</body>
</html>
