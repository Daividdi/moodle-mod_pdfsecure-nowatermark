# tools/

Scripts de site — **não fazem parte do plugin**. O Moodle nunca os carrega; eles
existem para instalar, migrar e conferir. Copie para a raiz do Moodle, rode, e
depois **tire do webroot**.

| Script | Para quê |
| --- | --- |
| `migrate_pdf.php` | Migra atividades de outro módulo de PDF para `pdfsecure`, sem duplicar bytes e sem apagar nada |
| `verify_pdf.php` | Confere, por fora, que cada atividade migrada tem o arquivo certo (compara contenthash) |
| `enable_read_completion.php` | Ativa "Ler até o fim" nas atividades existentes e restaura os valores anteriores |
| `pstest-setup.php` | Cria um usuário temporário matriculado, para o teste HTTP |
| `pstest.sh` | Prova por HTTP que a entrega está correta e que o original não tem rota |
| `pstest-cleanup.php` | Apaga o usuário temporário |

## Ativar conclusão de leitura

Copie `enable_read_completion.php` para a raiz do Moodle, rode a simulação e
remova a cópia do webroot. O script considera apenas cursos com conclusão
ativada e módulos que não estejam em exclusão. Não apaga atividades nem altera
os registros existentes de conclusão dos alunos.

```bash
cd /caminho/do/moodle

# Simulacao; nao grava no banco nem cria o arquivo de rollback
sudo -u www-data php enable_read_completion.php

# Simulacao limitada a duas atividades em um curso
sudo -u www-data php enable_read_completion.php --course=12 --limit=2

# Aplicacao; o rollback e obrigatorio e criado antes de qualquer alteracao
sudo -u www-data php enable_read_completion.php --commit --rollback=/caminho/pdfsecure-completion.json

# Simule a restauracao e, depois de conferir a lista, aplique-a
sudo -u www-data php enable_read_completion.php --restore=/caminho/pdfsecure-completion.json
sudo -u www-data php enable_read_completion.php --restore=/caminho/pdfsecure-completion.json --commit
```

Cada atividade elegível passa para conclusão automática, sem exigir visualização,
com a regra `completionreadall` ativada. Atividades que já usam essa regra são
listadas e ignoradas. Atividades com conclusão manual também são convertidas;
os estados de conclusão dos usuários permanecem intactos. O rollback guarda os
valores anteriores de `completion`, `completionview`, `completionexpected`,
`completionpassgrade` quando disponível e `completionreadall`.

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

## Origem `resource` e `folder` (Moodle nativo)

- `--source=resource`: cada "Arquivo" cujo arquivo e um PDF vira uma atividade pdfsecure, como os outros modulos. O mesmo conversor compartilhado e usado pelos eventos e pela tarefa agendada do plugin.
- `--source=folder`: uma pasta guarda varios arquivos e o pdfsecure guarda um so, entao **cada PDF da pasta vira uma
  atividade propria**, na ordem da pasta, logo depois dela, com o nome "Pasta — arquivo" quando ha mais de um. As marcas
  sao `migrado-folder-<cmid>` (primeiro PDF) e `migrado-folder-<cmid>-<n>` (os seguintes). A pasta so e escondida quando
  todos os PDFs ja estao conferidos nas novas e ela nao tem nada alem de PDF: uma pasta com video continua visivel e os
  PDFs confirmados saem da pasta. Se um PDF Secure marcado ja tiver sido atualizado, ele e preservado e o PDF restante
  ganha uma copia protegida com marca `-source` antes de sair da pasta.
- O conversor preserva disponibilidade e visibilidade; Arquivo e Pasta nao têm modo de grupo. Para Arquivo, transfere as conclusoes existentes e redireciona os criterios do curso e as condicoes de disponibilidade que dependiam do modulo antigo.
- A configuracao **PDFs somente como PDF Secure** ativa a validacao dos formularios, a conversao por evento e a varredura horaria. Ela fica desligada por padrao. A migracao explicita da CLI continua funcionando mesmo quando a configuracao esta desligada.
- A leitura ate o fim so e ativada automaticamente na nova atividade quando o curso tem conclusao habilitada e o modulo Arquivo original rastreava conclusao.

## Origem `pdfprotect`

`--source=pdfprotect` usa o mesmo conversor das atividades Arquivo, incluindo disponibilidade,
visibilidade, posição, conclusão, critérios e condições de disponibilidade. Ele preserva a atividade
original oculta, sem removê-la nem desinstalar `mod_pdfprotect`. Quando **PDFs somente como PDF Secure**
está ativada, os eventos e a varredura horária também convertem atividades `pdfprotect`; sem o módulo
instalado, essa origem não é considerada.

Outros módulos de PDF de terceiros continuam usando o caminho genérico da ferramenta.

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
