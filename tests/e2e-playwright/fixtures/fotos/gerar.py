# Gera as imagens de teste das fotos assíncronas (CT-FOTO-*). Uso: python3 gerar.py
import os
from PIL import Image
im=Image.new('RGB',(2400,1600),(30,120,200))
ex=Image.Exif(); ex[0x010F]='CameraQA'; ex[0x0110]='ModeloQA'; ex[0x0132]='2026:10:14 12:00:00'; ex[0x0112]=6
ex[0x8825]={1:'S',2:(27.0,35.0,10.5),3:'W',4:(48.0,32.0,5.25)}
im.save('com-exif-gps.jpg',exif=ex.tobytes(),quality=85)
Image.new('RGB',(1000,700),(200,80,40)).save('valida.png')
Image.new('RGB',(640,480),(10,200,90)).save('valida.jpg',quality=85)
open('texto-falso.jpg','w').write("<?php echo 'pwn'; ?>\nnao sou imagem\n")
b=open('valida.jpg','rb').read(); open('poliglota.jpg','wb').write(b+b"<?php system($_GET['c']); ?><script>alert(1)</script>")
open('corrompida.jpg','wb').write(b[:600]+os.urandom(4000))
Image.frombytes('RGB',(2600,2300),os.urandom(2600*2300*3)).save('grande-17mb.png',compress_level=0)
Image.new('RGB',(6000,4500),(5,5,5)).save('26mp.png')
for f in sorted(os.listdir('.')): print(f, os.path.getsize(f))
