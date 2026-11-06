FROM httpd:2.4
COPY infra/prodlocal/apache/sigarb.conf /usr/local/apache2/conf/extra/sigarb.conf
RUN sed -i -e 's/^#\(LoadModule \(proxy\|proxy_fcgi\|headers\|rewrite\|deflate\|expires\)_module\)/\1/' \
           -e 's/^\(LoadModule status_module\)/#\1/' /usr/local/apache2/conf/httpd.conf \
 && echo 'Include conf/extra/sigarb.conf' >> /usr/local/apache2/conf/httpd.conf
COPY frontend/dist/ /usr/local/apache2/htdocs/
