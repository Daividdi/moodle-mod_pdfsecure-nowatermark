# tools/

Scripts de site — **não fazem parte do plugin**. O Moodle nunca os carrega; eles
existem para instalar, migrar e conferir. Copie para a raiz do Moodle, rode, e
depois **tire do webroot**.

| Script | Para quê |
| --- | --- |
| `migrate_pdf.php` | Migra atividades de outro módulo de PDF para `pdfsecure`, sem duplicar bytes e sem apagar nada |
| `verify_pdf.php` | Confere, por fora, que cada atividade migrada tem o arquivo certo (compara contenthash) |
| `pstest-setup.php` | Cria um usuário temporário matriculado, para o teste HTTP |
| `pstest.sh` | Prova por HTTP que a entrega está correta e que o original não tem rota |
| `pstest-cleanup.php` | Apaga o usuário temporário |

## Ordem

```bash
cd /caminho/do/moodle

# 1. simulação — não grava nada
sudo -u www-data php migrate_pdf.php --source=<modulo>

# 2. piloto de duas, e olhe no navegador
sudo -u www-data php migrate_pdf.php --source=<modulo> --commit --limit=2

# 3. o resto
sudo -u www-data php migrate_pdf.php --source=<modulo> --commit

# 4. conferidor independente
sudo -u www-data php verify_pdf.php --source=<modulo>

# 5. os migrados só entram na busca depois disto
sudo -u www-data php search/cli/indexer.php --reindex
```

`--source` é o nome do módulo na tabela `modules` (ex.: `pdfprotect`). Descubra
com uma consulta em `{modules}` × `{course_modules}`.

## Duas coisas que custaram uma rodada

**`pdfsecure_add_instance()` só grava o arquivo `if ($mform)`** — ou seja, só
quando a atividade nasce do formulário web. Criada por script, ela nasce **sem
arquivo e sem erro nenhum**. Por isso o anexo é feito por fora e a original só é
escondida depois de confirmar o arquivo na nova. É também por isso que o
conferidor existe separado: o migrador dizia "erros: 0".

**`create_file_from_storedfile()` preserva o `timecreated` da origem**, então o
indexador acha que já conhece o arquivo e não o recolhe. Sem `--reindex` no fim,
os PDFs migrados não entram na busca — que é o motivo de migrar.

## Nunca

Não desinstale o módulo de origem depois de migrar. Desinstalar apaga as
atividades **e os arquivos** junto. As originais ficam escondidas, não removidas.
