# 使用一个成熟的 Nginx + PHP-FPM 镜像
FROM richarvey/nginx-php-fpm:latest

# 设定工作目录，和我们的文件路径进行挂载映射
WORKDIR /var/www/html

# 将当前目录下的所有文件复制到镜像的工作目录中
COPY . .

# 这里可以定义 nginx 的站点配置文件 (如果需要)
# COPY default.conf /etc/nginx/conf.d/default.conf
