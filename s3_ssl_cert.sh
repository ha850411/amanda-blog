#!/bin/bash

aws s3 cp s3://amanda-blog/ssl/cert.pem ./system/nginx/ssl/cert.pem
aws s3 cp s3://amanda-blog/ssl/key.pem ./system/nginx/ssl/key.pem

echo "SSL cert and key updated."