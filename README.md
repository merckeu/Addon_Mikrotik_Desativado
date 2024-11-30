# Addon_Mikrotik_Desativado
Esse Addon tem como Objetivo pegar clientes que foram desativados pelo mk-auth e enviar o login para servidor Mikrotik

# Resumo
Esse Addon a funcao dele é pegar o login do cliente que foi desativado pelo mk-auth e enviar para servidor mikrotik, 
sendo necessario criar usuario no servidor mikrotik, para addon ter acesso, o addon utiliza a porta " 8728 "

esse addon é util para aqueles clientes que ainda estao ativos no servidor, mas nao usam mais internet, ficando discando no servidor enchendo os logs.

Se o cliente for reativado o addon remove cliente automaticamente do servidor.

Precisa dar permissao 775 para pasta RouterOS-API 

É necessario criar Profile com nome PG-Corte e Adress List --> pgcorte , e criar uma pool de ips para que o cliente pegue um ip e seja adicionado ao PG-Corte.

----------------------------------------------------------------------------------------------

1. Para colocar seu IP, Usuario, Senha é somente ir na engrenagem no canto direito do addon que vai ter os campos designados para isso.

2. Para agendar é só clicar no ícone de calendário, que lá vai ter opção de agendar o Intervalo (min):.

3. Para funcionar na TUX 4.19 precisa adicionar permissões do " apparmor "

4. Vá para o diretório /etc/apparmor.d e abra o arquivo usr.sbin.php-fpm7.3.

5. Adicione estas linha no arquivo:

        #Addon Recibo Whatsapp
        /opt/mk-auth/dados/Mikrotik_Desativado/ rwk,
        /opt/mk-auth/dados/Mikrotik_Desativado/** rwk,




   


 Caso não queira reiniciar o MK-auth só dar esses dois comando abaixo.

```
sudo apparmor_parser -r /etc/apparmor.d/usr.sbin.php-fpm7.3
```
```
sudo service php7.3-fpm restart
```
