# Amanda's Blog

# 使用技術
![Laravel 12](https://img.shields.io/badge/Laravel_12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![Vue 3](https://img.shields.io/badge/Vue_3-35495E?style=for-the-badge&logo=vue.js&logoColor=4FC08D)
![MySQL 8.0](https://img.shields.io/badge/MySQL_8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Redis 7.0](https://img.shields.io/badge/Redis_7.0-DD0031?style=for-the-badge&logo=redis&logoColor=white)

# 前置作業
本機環境需安裝 docker、docker-compose
- docker: https://www.docker.com/products/docker-desktop/
- docker-compose: https://docs.docker.com/compose/install/

# 安裝
啟動 docker
```
/workspace/amanda-blog
> make up
```
安裝 composer 套件
```
/workspace/amanda-blog
> make composer-install
```

# 測試
建立測試資料庫
```
/workspace/amanda-blog
> make ensure-testing-db
```
執行測試
```
/workspace/amanda-blog
> make test
```

# 訪問專案 url
- 前台: http://localhost:8091
- 後台: http://localhost:8091/admin
