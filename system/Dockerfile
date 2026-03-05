FROM jenkins/jenkins:lts

USER root

# 設定時區為台灣時區 (Asia/Taipei)
ENV TZ=Asia/Taipei
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update && \
    apt-get install -y ca-certificates curl gnupg lsb-release sudo && \
    install -m 0755 -d /etc/apt/keyrings

RUN curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc && \
    chmod a+r /etc/apt/keyrings/docker.asc && \
    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian \
      $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

RUN apt-get update && \
    apt-get install -y docker-ce-cli make

# 建立 docker 群組 (如果它已經存在，這條指令會失敗，但可以被忽略)
RUN groupadd -f docker

# 將 jenkins 使用者加入 docker 群組
RUN usermod -aG docker jenkins

# 允許 jenkins 使用者無密碼使用 sudo 執行 docker 相關指令以及 rm 指令
RUN echo "jenkins ALL=(ALL) NOPASSWD: /usr/bin/docker, /usr/bin/docker-compose, /usr/local/bin/docker, /usr/local/bin/docker-compose, /bin/rm, /usr/bin/rm" >> /etc/sudoers

USER jenkins